<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class RegularGivingDonationRepositoryTest extends IntegrationTest
{
    public function testFindDonationsToSetPaymentIntent(): void
    {
        // arrange
        $atDateTime = new \DateTimeImmutable('2025-01-03T00:11:11');
        $sut = $this->getService(DonationRepository::class);

        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('123456789012345678')
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
        $mandate->activate($atDateTime);

        $donation = $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(2),
            $donor,
            $campaign
        );

        $donation->preAuthorize($atDateTime);

        $em->persist($donor);
        $em->persist($mandate);
        $em->persist($campaign);
        $em->persist($donation);
        $em->flush();

        $donations = $sut->findDonationsToSetPaymentIntent($atDateTime, 10);
        $this->assertCount(1, $donations);
        $this->assertEquals($donation->getUuid(), $donations[0]->getUuid());
    }
}
