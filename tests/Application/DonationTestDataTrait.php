<?php

namespace MatchBot\Tests\Application;

use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;
use Stripe\Charge;

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

    /**
     * @param numeric-string $amount
     */
    protected function getPendingBigGiveGeneralCustomerBalanceDonation(
        string $amount = '123.45',
        string $currencyCode = 'GBP',
    ): Donation {
        $campaignId = '567BgCampId';
        $campaign = new Campaign(charity: TestCase::someCharity());
        $campaign->setSalesforceId($campaignId);
        $campaign->setIsMatched(false);
        $campaign->setName('Big Give General Donations');

        $data = new DonationCreate(
            currencyCode: $currencyCode,
            donationAmount: $amount,
            projectId: $campaignId,
            pspMethodType: PaymentMethodType::CustomerBalance,
            psp: 'stripe',
            pspCustomerId: 'cus_123',
        );

        $donation = Donation::fromApiModel($data, $campaign);
        $this->setMinimumFieldsSetOnFirstPersist($donation);

        return $donation;
    }

    protected function getTestDonation(
        string $amount = '123.45',
        PaymentMethodType $pspMethodType = PaymentMethodType::Card,
        string $tipAmount = '1.00',
        string $currencyCode = 'GBP',
    ): Donation {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setSalesforceId('123CharityId');
        $charity->setName('Test charity');
        $charity->setStripeAccountId('unitTest_stripeAccount_123');

        $campaign = new Campaign(charity: $charity);
        $campaign->setIsMatched(true);
        // This name ensures that if an auto-confirm Update specifically hits the display_bank_transfer_instructions
        // next action, we don't cancel the pending donation.
        $campaign->setName('Big Give General Donations');
        $campaign->setSalesforceId('456ProjectId');

        /** @psalm-suppress DeprecatedMethod **/
        $donation = Donation::emptyTestDonation(
            amount: $amount,
            paymentMethodType: $pspMethodType,
            currencyCode: $currencyCode,
        );

        $this->setMinimumFieldsSetOnFirstPersist($donation);

        $donation->setCharityFee('2.05');
        $donation->setCampaign($campaign);
        $donation->setChampionComms(false);

        $donation->collectFromStripeCharge(
            chargeId: 'ch_externalId_123',
            transferId: 'tr_externalId_123',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: (int)(new \DateTimeImmutable())->format('U'),
        );

        $donation->setDonorCountryCode('GB');
        $donation->setDonorEmailAddress('john.doe@example.com');
        $donation->setDonorFirstName('John');
        $donation->setDonorLastName('Doe');
        $donation->setDonorBillingAddress('1 Main St, London N1 1AA');
        $donation->setDonorHomeAddressLine1('1 Main St, London'); // Frontend typically includes town for now
        $donation->setDonorHomePostcode('N1 1AA');
        $donation->setGiftAid(true);
        $donation->setSalesforceId('sfDonation369');
        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
        $donation->setTipAmount($tipAmount);
        $donation->setTransactionId('pi_externalId_123');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));

        return $donation;
    }

    protected function getAnonymousPendingTestDonation(): Donation
    {
        $campaign = new Campaign(charity: TestCase::someCharity());
        $campaign->setIsMatched(true);
        $campaign->setName('Test campaign');
        $campaign->setSalesforceId('456ProjectId');

        $donation = Donation::emptyTestDonation('124.56');
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCharityFee('2.57');
        $donation->setCampaign($campaign);
        $donation->setDonationStatus(DonationStatus::Pending);
        $donation->setTransactionId('pi_stripe_pending_123');
        $donation->setTipAmount('2.00');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ac'));

        return $donation;
    }

    private function setMinimumFieldsSetOnFirstPersist(Donation $donation): void
    {
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setTransactionId('pi_externalId_123');
        $donation->setGiftAid(true);
        $donation->setCharityComms(true);
        $donation->setTbgComms(false);
    }
}
