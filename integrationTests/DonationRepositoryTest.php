<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Client\Fund;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\TestCase;

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
        $campaign->setSalesforceId('ccampaign123');
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
            giftAid: true,
            projectId: 'ccampaign123',
            psp: 'stripe',
            paymentMethodType: PaymentMethodType::CustomerBalance
        ), $campaign);
        $donation->setDonationStatus(DonationStatus::Paid);
        $donation->setCollectedAt((new \DateTimeImmutable())->sub(new \DateInterval('P14D')));

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
        $thirtyThreeMinsInFuture = (new \DateTime('now'))->modify('+33 minute');

        // act
        $expiredDonations = $sut->findWithExpiredMatching($thirtyThreeMinsInFuture);

        // assert
        $expiredDonationStatuses = array_map(
            fn(Donation $donation) => $donation->getDonationStatus(),
            array_filter($expiredDonations, fn(Donation $dn) => $dn->getDonorEmailAddress() === $randomEmailAddress)
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
                projectId: 'projectID',
                psp: 'stripe',
                emailAddress: $randomEmailAddress,
            ), campaign: $campaign
        );
        if ($donationStatus === DonationStatus::Cancelled) {
            $oldPendingDonation->cancel();
        } else {
            $oldPendingDonation->setDonationStatus($donationStatus);
        }
        $fundingWithdrawal = new FundingWithdrawal();
        $oldPendingDonation->addFundingWithdrawal($fundingWithdrawal);
        $fundingWithdrawal->setAmount('1');
        $fundingWithdrawal->setDonation($oldPendingDonation);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($fundingWithdrawal);
        $em->persist($oldPendingDonation->getCampaign());
        $em->persist($oldPendingDonation->getCampaign()->getCharity());
        $em->persist($oldPendingDonation);

        $em->flush();
    }
}
