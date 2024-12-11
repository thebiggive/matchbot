<?php

namespace MatchBot\IntegrationTests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Pledge;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

class DonationRepositoryTest extends IntegrationTest
{
    private const string PSP_CUSTOMER_ID = 'cus_inttest_1';

    public function setUp(): void
    {
        parent::setUp();
        $this->clearPreviousCampaignsCharitiesAndRelated(); // Avoid e.g. HMRC ref dupes
    }

    public function testItFindsGiftAidSendableDonationsForCharityThatIsReady(): void
    {
        $charity = $this->prepareOnboardedCharity(withAgentApproval: true);
        $donation = $this->prepareAndPersistDonation($charity);

        $sut = $this->getService(DonationRepository::class);
        $donationsReady = $sut->findReadyToClaimGiftAid(withResends: false);

        $this->assertEquals([$donation], $donationsReady);
    }

    public function testItFindsNoGiftAidSendableDonationsForCharityPendingAgentApproval(): void
    {
        $charity = $this->prepareOnboardedCharity(withAgentApproval: false);
        $this->prepareAndPersistDonation($charity);

        $sut = $this->getService(DonationRepository::class);
        $donationsReady = $sut->findReadyToClaimGiftAid(withResends: false);

        $this->assertEmpty($donationsReady);
    }

    private function prepareOnboardedCharity(bool $withAgentApproval): Charity
    {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setName('Charity Name');
        $charity->setHmrcReferenceNumber('any-ref');
        $charity->setTbgClaimingGiftAid(true);
        $charity->setTbgApprovedToClaimGiftAid($withAgentApproval);

        return $charity;
    }
    private function prepareAndPersistDonation(Charity $charity): Donation
    {
        $campaign = $this->makeCampaign($charity);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);

        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '300',
            projectId: 'ccampaign123456789',
            psp: 'stripe',
            pspMethodType: PaymentMethodType::CustomerBalance
        ), $campaign);

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: "home address",
        );

        $donation->collectFromStripeCharge(
            chargeId: 'charge_id',
            totalPaidFractional: 300,
            transferId: 'transfer_id',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: (new \DateTimeImmutable())->sub(new \DateInterval('P14D'))->getTimestamp()
        );

        $donation->setDonationStatus(DonationStatus::Paid);

        $em->persist($donation);
        $em->flush(); // Cascade persists campaign and charity.

        return $donation;
    }

    public function testItFindsExpiredDonations(): void
    {
        // arrange
        $campaign = $this->makeCampaign();
        $randomEmailAddress = 'email' . random_int(1000, 99999) . '@example.com';

        $this->makeDonation($randomEmailAddress, $campaign, DonationStatus::Pending);
        $this->makeDonation($randomEmailAddress, $campaign, DonationStatus::Cancelled);

        $sut = $this->getService(DonationRepository::class);
        $thirtyThreeMinsInFuture = (new \DateTimeImmutable('now'))->modify('+33 minute');

        // act
        $expiredDonationsIDs = $sut->findWithExpiredMatching($thirtyThreeMinsInFuture);
        $expiredDonations = $sut->findBy(['uuid' => $expiredDonationsIDs]);

        // assert
        $expiredDonationStatuses = array_map(
            fn(Donation $donation) => $donation->getDonationStatus(),
            array_filter(
                $expiredDonations,
                fn(Donation $dn) => $dn->getDonorEmailAddress() == EmailAddress::of($randomEmailAddress)
            )
        );

        $this->assertEqualsCanonicalizing(
            [DonationStatus::Pending, DonationStatus::Cancelled],
            $expiredDonationStatuses
        );
    }

    public function testItFindsDonationsToCancel(): void
    {
        // arrange
        $campaign = $this->makeCampaign();
        $campaignId = $campaign->getSalesforceId();
        $randomEmailAddress = 'email' . random_int(1000, 99999) . '@example.com';

        $this->makeDonation(
            $randomEmailAddress,
            $campaign,
            DonationStatus::Pending,
            PaymentMethodType::CustomerBalance,
        );

        $sut = $this->getService(DonationRepository::class);

        // act
        $cancelReadyDonations = $sut->findPendingByDonorCampaignAndMethod(
            self::PSP_CUSTOMER_ID,
            Salesforce18Id::ofCampaign($campaignId),
            PaymentMethodType::CustomerBalance
        );

        // assert
        $this->assertCount(1, $cancelReadyDonations);
        $donation = $sut->findOneBy(['uuid' => $cancelReadyDonations[0]]);
        \assert($donation !== null);

        $this->assertEquals(
            DonationStatus::Pending,
            $donation->getDonationStatus()
        );
    }

    public function makeCampaign(?Charity $charity = null): Campaign
    {
        return new Campaign(
            Salesforce18Id::ofCampaign('campaignId12345678'),
            $charity ?? TestCase::someCharity(),
            startDate: new \DateTimeImmutable('now'),
            endDate: new \DateTimeImmutable('now'),
            isMatched: true,
            ready: true,
            status: 'status',
            name: 'Campaign Name',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );
    }

    private function makeDonation(
        string $randomEmailAddress,
        Campaign $campaign,
        DonationStatus $donationStatus,
        PaymentMethodType $paymentMethodType = PaymentMethodType::Card,
    ): void {
        $oldPendingDonation = Donation::fromApiModel(
            donationData: new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectID123456789',
                psp: 'stripe',
                pspMethodType: $paymentMethodType,
                pspCustomerId: self::PSP_CUSTOMER_ID,
                emailAddress: $randomEmailAddress,
            ),
            campaign: $campaign
        );
        if ($donationStatus === DonationStatus::Cancelled) {
            $oldPendingDonation->cancel();
        } else {
            $oldPendingDonation->setDonationStatus($donationStatus);
        }

        $pledge = new Pledge(currencyCode: 'GBP', name: '');
        $campaignFunding = new CampaignFunding(
            fund: $pledge,
            amount: '1.0',
            amountAvailable: '1.0',
            allocationOrder: 100,
        );
        $fundingWithdrawal = new FundingWithdrawal($campaignFunding);
        $oldPendingDonation->addFundingWithdrawal($fundingWithdrawal);
        $fundingWithdrawal->setAmount('1');
        $fundingWithdrawal->setDonation($oldPendingDonation);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($pledge);
        $em->persist($campaignFunding);
        $em->persist($fundingWithdrawal);
        $em->persist($oldPendingDonation->getCampaign());
        $em->persist($oldPendingDonation->getCampaign()->getCharity());
        $em->persist($oldPendingDonation);

        $em->flush();
    }

    /**
     * @dataProvider donationAgesDataProvider
     */
    public function testPushSalesForcePendingPushesNewDonationButNotVeryNewDonation(
        int $donationAgeSeconds,
        bool $shouldPush
    ): void {
        // arrange
        $connection = $this->getService(Connection::class);
        $response = $this->createDonation();
        /** @psalm-suppress MixedArrayAccess */
        $donationUUID = json_decode((string)$response->getBody(), associative: true)['donation']['donationId'];
        \assert(is_string($donationUUID));

        $sut = $this->getService(DonationRepository::class);
        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);

        $busProphecy = $this->prophesize(RoutableMessageBus::class);

        $pendingCreate = \MatchBot\Domain\SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE;
        $connection->executeStatement(<<<SQL
            UPDATE Donation set salesforcePushStatus = "$pendingCreate", salesforceLastPush = null
            WHERE uuid = "$donationUUID"
            LIMIT 1;
        SQL
        );

        $sut->setClient($donationClientProphecy->reveal());

        $simulatedNow = (new \DateTimeImmutable())->modify('+' . $donationAgeSeconds . ' seconds');
        \assert($simulatedNow instanceof \DateTimeImmutable);

        // assert
        $busDispatchMethod = $busProphecy->dispatch(Argument::type(Envelope::class))
            ->will(
                /**
                 * @param array{0: Envelope} $args
                 */
                function (array $args) use ($donationUUID) {
                    $envelope = $args[0];
                    $message = $envelope->getMessage();
                    TestCase::assertInstanceOf(DonationUpserted::class, $message);
                    TestCase::assertSame($donationUUID, $message->uuid);
                    return $envelope;
                }
            );

        if ($shouldPush) {
            $busDispatchMethod->shouldBeCalledOnce();
        } else {
            $busDispatchMethod->shouldNotBeCalled();
        }

        // act
        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy->upsertedMessageFromDonation(Argument::type(Donation::class))->willReturn(new DonationUpserted($donationUUID, []));
        $donationService = $donationServiceProphecy->reveal();
        $sut->pushSalesforcePending(
            now: $simulatedNow,
            bus: $busProphecy->reveal(),
            donationService: $donationService
        );
    }

    /**
     * @return list<array{0: int, 1: bool}>
     */
    public function donationAgesDataProvider(): array
    {
        // age in seconds, should be pushed to sf
        return [
            [0, false],
            [299, false],
            [300, true],
        ];
    }
}
