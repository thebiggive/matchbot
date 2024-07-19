<?php

namespace MatchBot\IntegrationTests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Pledge;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;

class DonationRepositoryTest extends IntegrationTest
{
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

    private function prepareCampaign(Charity $charity): Campaign
    {
        $campaign = new Campaign(charity: $charity);
        $campaign->setName('Campaign Name');
        $campaign->setSalesforceId('ccampaign123456789');
        $campaign->setCurrencyCode('GBP');
        $campaign->setStartDate((new \DateTime())->sub(new \DateInterval('P16D')));
        $campaign->setEndDate((new \DateTime())->add(new \DateInterval('P15D')));
        $campaign->setIsMatched(true);

        return $campaign;
    }

    private function prepareAndPersistDonation(Charity $charity): Donation
    {
        $campaign = $this->prepareCampaign($charity);

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
        $expiredDonations = $sut->findWithExpiredMatching($thirtyThreeMinsInFuture);

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


    public function makeCampaign(): Campaign
    {
        $campaign = new Campaign(TestCase::someCharity());
        $campaign->setCurrencyCode('GBP');
        $campaign->setStartDate(new \DateTime());
        $campaign->setEndDate(new \DateTime());
        $campaign->setIsMatched(true);

        $campaign->setName('Campaign Name');
        return $campaign;
    }

    public function makeDonation(string $randomEmailAddress, Campaign $campaign, DonationStatus $donationStatus): void
    {
        $oldPendingDonation = Donation::fromApiModel(
            donationData: new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectID123456789',
                psp: 'stripe',
                emailAddress: $randomEmailAddress,
            ),
            campaign: $campaign
        );
        if ($donationStatus === DonationStatus::Cancelled) {
            $oldPendingDonation->cancel();
        } else {
            $oldPendingDonation->setDonationStatus($donationStatus);
        }

        $pledge = new Pledge();
        $pledge->setCurrencyCode('GBP');
        $pledge->setName('');
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setFund($pledge);
        $campaignFunding->createdNow();
        $campaignFunding->setFund($campaignFunding->getFund());
        $campaignFunding->setAllocationOrder(100);
        $campaignFunding->setCurrencyCode('GBP');
        $campaignFunding->setAmount('1.0');
        $campaignFunding->setAmountAvailable('1.0');
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
        /** @var Donation $donationInDB */

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
        $method = $donationClientProphecy->create(Argument::type(Donation::class));
        if ($shouldPush) {
            $method->shouldBeCalledOnce();
        } else {
            $method->shouldNotBeCalled();
        }

        // act
        $sut->pushSalesforcePending(now: $simulatedNow);
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
