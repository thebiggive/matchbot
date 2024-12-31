<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class DoctrineDonationRepositoryTest extends IntegrationTest
{

    public function setUp(): void
    {
        parent::setUp();
    }

    public function testFindDonationsToSetPaymentIntent()
    {
        // arrange
        $atDateTime = new \DateTimeImmutable('now');
        $sut = $this->getService(DonationRepository::class);

        $amount = '20';
        $currencyCode = 'GBP';
        $paymentMethodType = PaymentMethodType::Card;
        $giftAid = false;

        $donation = new Donation(
            amount: $amount,
            currencyCode: $currencyCode,
            paymentMethodType: $paymentMethodType,
            campaign: TestCase::someCampaign(
                sfId: Salesforce18Id::ofCampaign('123456789012345678'),
            ),
            charityComms: null,
            championComms: null,
            pspCustomerId: null,
            optInTbgEmail: null,
            donorName: null,
            emailAddress: null,
            countryCode: null,
            tipAmount: '0',
            mandate: null,
            mandateSequenceNumber: null,
            giftAid: $giftAid,
            tipGiftAid: null,
            homeAddress: null,
            homePostcode: null,
            billingPostcode: null
        );
        $em = $this->getService(EntityManagerInterface::class);
//        $em->persist($donation);
//        $em->flush();

//        $donations = $sut->findDonationsToSetPaymentIntent($atDateTime, 10);
//
//        $this->assertNotNull($donations[0]);
//        $this->assertEquals(, $donations[0]->getDonationStatus());
    }
}
