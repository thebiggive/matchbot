<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;

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
        $charity = new Charity();
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
}
