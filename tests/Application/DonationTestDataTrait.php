<?php

namespace MatchBot\Tests\Application;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\SalesforceWriteProxy;
use Ramsey\Uuid\Uuid;

trait DonationTestDataTrait
{
    protected function getStripeHookMock(string $path): string
    {
        $fullPath = sprintf(
            '%s/TestData/StripeWebhook/%s.json',
            dirname(__DIR__),
            $path,
        );

        return file_get_contents($fullPath);
    }

    protected function getTestDonation(
        string $amount = '123.45',
        PaymentMethodType $paymentMethodType = PaymentMethodType::Card,
        string $tipAmount = '1.00',
    ): Donation
    {
        $charity = new Charity();
        $charity->setSalesforceId('123CharityId');
        $charity->setName('Test charity');
        $charity->setStripeAccountId('unitTest_stripeAccount_123');

        $campaign = new Campaign(charity: $charity);
        $campaign->setIsMatched(true);
        $campaign->setName('Test campaign');
        $campaign->setSalesforceId('456ProjectId');

        $donation = Donation::emptyTestDonation($amount, $paymentMethodType);
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCharityFee('2.05');
        $donation->setCampaign($campaign);
        $donation->setCharityComms(true);
        $donation->setChampionComms(false);
        $donation->setCurrencyCode('GBP');
        $donation->setDonationStatus(DonationStatus::Collected);
        $donation->setCollectedAt(new \DateTimeImmutable());
        $donation->setDonorCountryCode('GB');
        $donation->setDonorEmailAddress('john.doe@example.com');
        $donation->setDonorFirstName('John');
        $donation->setDonorLastName('Doe');
        $donation->setDonorBillingAddress('1 Main St, London N1 1AA');
        $donation->setDonorHomeAddressLine1('1 Main St, London'); // Frontend typically includes town for now
        $donation->setDonorHomePostcode('N1 1AA');
        $donation->setGiftAid(true);
        $donation->setPsp('stripe');
        $donation->setSalesforceId('sfDonation369');
        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
        $donation->setTbgComms(false);
        $donation->setTipAmount($tipAmount);
        $donation->setTransferId('tr_externalId_123');
        $donation->setTransactionId('pi_externalId_123');
        $donation->setChargeId('ch_externalId_123');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));

        return $donation;
    }

    protected function getAnonymousPendingTestDonation(): Donation
    {
        $charity = new Charity();
        $charity->setSalesforceId('123CharityId');
        $charity->setName('Test charity');

        $campaign = new Campaign(charity: $charity);
        $campaign->setIsMatched(true);
        $campaign->setName('Test campaign');
        $campaign->setSalesforceId('456ProjectId');

        $donation = Donation::emptyTestDonation('124.56');
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCharityFee('2.57');
        $donation->setCampaign($campaign);
        $donation->setCurrencyCode('GBP');
        $donation->setDonationStatus(DonationStatus::Pending);
        $donation->setPsp('stripe');
        $donation->setTransactionId('pi_stripe_pending_123');
        $donation->setTipAmount('2.00');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ac'));

        return $donation;
    }
}
