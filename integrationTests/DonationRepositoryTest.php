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
    public function testItFindsGiftAidSendableDonationsForCharityThatIsReady(): void
    {
        $charity = $this->prepareOnboardedCharity(withAgentApproval: true);
        $donation = $this->prepareDonation($charity);

        $sut = $this->getService(DonationRepository::class);
        $donationsReady = $sut->findReadyToClaimGiftAid(withResends: false);

        $this->assertEquals([$donation], $donationsReady);
    }

    public function testItFindsNoGiftAidSendableDonationsForCharityPendingAgentApproval(): void
    {
        $charity = $this->prepareOnboardedCharity(withAgentApproval: false);
        $this->prepareDonation($charity);

        $sut = $this->getService(DonationRepository::class);
        $donationsReady = $sut->findReadyToClaimGiftAid(withResends: false);

        $this->assertEquals([], $donationsReady);
    }

    private function prepareOnboardedCharity(bool $withAgentApproval): Charity
    {
        $charity = new Charity();
        $charity->setName('Charity Name');
        $charity->setHmrcReferenceNumber($withAgentApproval ? 'YY54321' : 'NN54321');
        $charity->setTbgClaimingGiftAid(true);
        $charity->setTbgApprovedToClaimGiftAid($withAgentApproval);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($charity);
        $em->flush();

        return $charity;
    }

    private function prepareDonation(Charity $charity): Donation
    {
        $campaign = new Campaign();
        $campaign->setCharity($charity);
        $campaign->setSalesforceId('ccampaign123');

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
        $em->flush();

        return $donation;
    }
}
