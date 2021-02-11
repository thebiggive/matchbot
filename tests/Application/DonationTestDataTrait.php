<?php

namespace MatchBot\Tests\Application;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use Ramsey\Uuid\Uuid;

trait DonationTestDataTrait
{
    protected function getTestDonation(): Donation
    {
        $charity = new Charity();
        $charity->setDonateLinkId('123CharityId');
        $charity->setName('Test charity');

        $campaign = new Campaign();
        $campaign->setCharity($charity);
        $campaign->setIsMatched(true);
        $campaign->setName('Test campaign');
        $campaign->setSalesforceId('456ProjectId');

        $donation = new Donation();
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setAmount('123.45');
        $donation->setCharityFee('2.05');
        $donation->setCampaign($campaign);
        $donation->setCharityComms(true);
        $donation->setChampionComms(false);
        $donation->setDonationStatus('Collected');
        $donation->setDonorCountryCode('GB');
        $donation->setDonorEmailAddress('john.doe@example.com');
        $donation->setDonorFirstName('John');
        $donation->setDonorLastName('Doe');
        $donation->setDonorBillingAddress('1 Main St, London N1 1AA');
        $donation->setGiftAid(true);
        $donation->setPsp('stripe');
        $donation->setSalesforceId('sfDonation369');
        $donation->setTbgComms(false);
        $donation->setTipAmount('1.00');
        $donation->setTransactionId('pi_externalId_123');
        $donation->setChargeId('ch_externalId_123');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));

        return $donation;
    }

    protected function getAnonymousPendingTestDonation(): Donation
    {
        $charity = new Charity();
        $charity->setDonateLinkId('123CharityId');
        $charity->setName('Test charity');

        $campaign = new Campaign();
        $campaign->setCharity($charity);
        $campaign->setIsMatched(true);
        $campaign->setName('Test campaign');
        $campaign->setSalesforceId('456ProjectId');

        $donation = new Donation();
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setAmount('124.56');
        $donation->setCharityFee('2.57');
        $donation->setCampaign($campaign);
        $donation->setDonationStatus('Pending');
        $donation->setPsp('enthuse');
        $donation->setTipAmount('2.00');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ac'));

        return $donation;
    }
}
