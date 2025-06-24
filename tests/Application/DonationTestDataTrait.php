<?php

namespace MatchBot\Tests\Application;

use DateTime;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

trait DonationTestDataTrait
{
    /**
     * @param array<string, mixed> $dataOverrides - partial array of data to override ach data item within a list
     */
    protected function getStripeHookMock(string $path, array $dataOverrides = []): string
    {
        $fullPath = sprintf(
            '%s/TestData/StripeWebhook/%s.json',
            dirname(__DIR__),
            $path,
        );

        $contents = file_get_contents($fullPath);

        \assert(is_string($contents));

        $contentsAsArray = \json_decode($contents, associative: true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($contentsAsArray));

        if (\array_key_exists('data', $contentsAsArray) && $dataOverrides !== []) {
            $data = $contentsAsArray['data'];
            \assert(\is_array($data) && \array_is_list($data));
            $contentsAsArray['data'] = \array_map(
                static fn(array $datum) => \array_merge($datum, $dataOverrides),
                $data
            );
        }

        return \json_encode($contentsAsArray, flags: \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param numeric-string $amount
     */
    protected function getPendingBigGiveGeneralCustomerBalanceDonation(
        string $amount = '123.00',
        string $currencyCode = 'GBP',
    ): Donation {
        $campaignId = 'testProject1234567';
        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign($campaignId),
        );
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

        $donation = Donation::fromApiModel($data, $campaign, PersonId::nil());
        $this->setMinimumFieldsSetOnFirstPersist($donation);

        return $donation;
    }

    /**
     * @param numeric-string $amount
     */
    protected function getTestDonation(
        string $amount = '123.45',
        PaymentMethodType $pspMethodType = PaymentMethodType::Card,
        string $tipAmount = '1.00',
        string $currencyCode = 'GBP',
        bool $collected = true,
        DateTime $tbgGiftAidRequestConfirmedCompleteAt = null,
        bool $charityComms = false,
        null|UuidInterface|string $uuid = null,
    ): Donation {
        $charity = TestCase::someCharity();
        $charity->setSalesforceId('123CharityId');
        $charity->setName('Test charity');
        $charity->setStripeAccountId('unitTest_stripeAccount_123');

        $campaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign('234567890pROjecTID'), charity: $charity);
        $campaign->setIsMatched(true);
        // This name ensures that if an auto-confirm Update specifically hits the display_bank_transfer_instructions
        // next action, we don't cancel the pending donation.
        $campaign->setName('Big Give General Donations');

        $donation = TestCase::someDonation(
            amount: $amount,
            paymentMethodType: $pspMethodType,
            currencyCode: $currencyCode,
        );
        $donation->setCampaign(TestCase::getMinimalCampaign());

        $this->setMinimumFieldsSetOnFirstPersist($donation);

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '1 Main St, London',  // Frontend typically includes town for now
            charityComms: $charityComms,
            donorBillingPostcode: 'N1 1AA',
        );

        $donation->setCharityFee('2.05');
        $donation->setCampaign($campaign);
        $donation->setChampionComms(false);

        if ($collected) {
            $donation->collectFromStripeCharge(
                chargeId: 'ch_externalId_123',
                totalPaidFractional: (int)(((float)$amount + (float)$tipAmount) * 100.0),
                transferId: 'tr_id_from_test_donation',
                cardBrand: null,
                cardCountry: null,
                originalFeeFractional: '122', // £1.22
                chargeCreationTimestamp: (int)(new \DateTimeImmutable())->format('U'),
            );
        }

        $donation->setDonorCountryCode('GB');
        $donation->setDonorEmailAddress(EmailAddress::of('john.doe@example.com'));
        $donation->setDonorName(DonorName::of('John', 'Doe'));
        $donation->setDonorHomePostcode('N1 1AA');
        $donation->setSalesforceId('sfDonation36912345');
        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
        $donation->setTipAmount($tipAmount);
        $donation->setTransactionId('pi_externalId_123');
        if ($uuid instanceof UuidInterface) {
            $donation->setUuid($uuid);
        }
        if (is_string($uuid)) {
            $donation->setUuid(Uuid::fromString($uuid));
        }
        $donation->setTbgGiftAidRequestConfirmedCompleteAt($tbgGiftAidRequestConfirmedCompleteAt);

        return $donation;
    }

    protected function getAnonymousPendingTestDonation(): Donation
    {
        $campaign = new Campaign(
            Salesforce18Id::ofCampaign('234567890pROjecTID'),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable(),
            endDate: new \DateTimeImmutable(),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
        );

        $donation = TestCase::someDonation(amount: '124.56');
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCharityFee('2.57');
        $donation->setCampaign($campaign);
        $donation->setDonationStatusForTest(DonationStatus::Pending);
        $donation->setTransactionId('pi_stripe_pending_123');
        $donation->setTipAmount('2.00');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ac'));

        return $donation;
    }

    private function setMinimumFieldsSetOnFirstPersist(Donation $donation): void
    {
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: 'Home Address'
        );

        $donation->setTransactionId('pi_externalId_123');
        $donation->setCharityComms(true);
        $donation->setTbgComms(false);
    }
}
