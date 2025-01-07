<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class RegularGivingDonationRepositoryTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @return list{ Donation, RegularGivingMandate }
     * @throws \Assert\AssertionFailedException
     * @throws \MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed
     */
    public function arrange(bool $activateMandate, \DateTimeImmutable $atDateTime): array
    {
    // arrange
        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign(self::randomString())
        );

        $em = $this->getService(EntityManagerInterface::class);

        $mandate = new \MatchBot\Domain\RegularGivingMandate(
            donorId: PersonId::of(Uuid::uuid4()->toString()),
            donationAmount: Money::fromPoundsGBP(20),
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: Salesforce18Id::ofCharity($campaign->getCharity()->getSalesforceId()),
            giftAid: false,
            dayOfMonth: DayOfMonth::of(2)
        );
        $donor = new DonorAccount(
            uuid: $mandate->donorId,
            emailAddress: EmailAddress::of('emailAddress@test.com'),
            donorName: DonorName::of('donorFName-test', 'donorLName-test'),
            stripeCustomerId: StripeCustomerId::of('cus_' . self::randomString())
        );
        $donor->setBillingCountryCode('GB');
        $donor->setBillingPostcode('W1 5YU');

        if ($activateMandate) {
            $mandate->activate($atDateTime);

            $donation = $mandate->createPreAuthorizedDonation(
                DonationSequenceNumber::of(2),
                $donor,
                $campaign,
                requireActiveMandate: true
            );
        } else {
            $donation = $mandate->createPreAuthorizedDonation(
                DonationSequenceNumber::of(2),
                $donor,
                $campaign,
                requireActiveMandate: false,
                expectedActivationDate: $atDateTime
            );
        }



        $em->persist($donor);
        $em->persist($mandate);
        $em->persist($campaign);
        $em->persist($donation);
        $em->flush();

        return [$donation, $mandate];
    }

    public function testFindDonationsToSetPaymentIntent(): void
    {
        $atDateTime = new \DateTimeImmutable('2025-01-03T00:11:11');
        list($donation) = $this->arrange(true, $atDateTime);
        $sut = $this->getService(DonationRepository::class);

        $donation->preAuthorize($atDateTime);
        $this->getService(EntityManagerInterface::class)->flush();
        $donations = $sut->findDonationsToSetPaymentIntent($atDateTime, 10);

        $this->assertCount(1, $donations);
        $this->assertEquals($donation->getUuid(), $donations[0]->getUuid());
    }

    public function testDoesntFindDonationsForPaymentIntentIfNotPreAuthorised(): void
    {
        $this->markTestIncomplete('will continue work');
        $atDateTime = new \DateTimeImmutable('2025-01-03T00:11:11');
        list($donation) = $this->arrange(false, $atDateTime);
        $sut = $this->getService(DonationRepository::class);


        $donations = $sut->findDonationsToSetPaymentIntent($atDateTime, 10);

        $this->assertCount(0, $donations);
        $this->assertEquals($donation->getUuid(), $donations[0]->getUuid());
    }

    public function testDoesntFindDonationsForPaymentIntentIfStatusNotActive(): void
    {
        $this->markTestIncomplete('will continue work');

        $atDateTime = new \DateTimeImmutable('2025-01-03T00:11:11');
        list($donation) = $this->arrange(false, $atDateTime);
        $sut = $this->getService(DonationRepository::class);


        $donations = $sut->findDonationsToSetPaymentIntent($atDateTime, 10);
        $this->assertCount(0, $donations);
        $this->assertEquals($donation->getUuid(), $donations[0]->getUuid());
    }
}
