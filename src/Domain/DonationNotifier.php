<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

class DonationNotifier
{
    public static function emailCommandForCollectedDonation(Donation $donation): SendEmailCommand
    {
        if (! $donation->getDonationStatus()->isSuccessful()) {
            throw new \RuntimeException("{$donation} is not successful - cannot send success email");
        }

        $paymentMethodType = $donation->getPaymentMethodType();
        $emailAddress = $donation->getDonorEmailAddress();
        $collectedAt = $donation->getCollectedAt();

        Assertion::notNull(
            $paymentMethodType,
            "payment method should not be null for successful donation: {$donation}"
        );

        Assertion::notNull(
            $emailAddress,
            "email address should not be null for successful donation: {$donation}"
        );

        Assertion::notNull(
            $collectedAt,
            "collectedAt should not be null for successful donation: {$donation}"
        );


        $campaign = $donation->getCampaign();
        $charity = $campaign->getCharity();

        $fundingWithdrawalsByType = $donation->getWithdrawalTotalByFundType();

        $matchedAmount = bcadd($fundingWithdrawalsByType['amountMatchedByPledges'], $fundingWithdrawalsByType['amountMatchedByChampionFunds'], 2);

        return SendEmailCommand::donorDonationSuccess($emailAddress, [
            // see required params in mailer:
            // https://github.com/thebiggive/mailer/blob/ca2c70f10720a66ff8fb041d3af430a07f49d625/app/settings.php#L27
            'campaignName' => $campaign->getCampaignName(),
            'campaignThankYouMessage' => $campaign->getThankYouMessage(),
            'charityName' => $charity->getName(),
            'currencyCode' => $donation->currency()->isoCode(),
            'donationAmount' => (float)$donation->getAmount(),
            'donorFirstName' => $donation->getDonorFirstName(),
            'paymentMethodType' => $paymentMethodType->value,
            'donationDatetime' => $collectedAt->format('c'),
            'donorLastName' => $donation->getDonorLastName(),
            'giftAidAmountClaimed' => (float) $donation->getGiftAidValue(),
            'matchedAmount' => (float) $matchedAmount,
            'tipAmount' => (float) $donation->getTipAmount(),
            'totalChargedAmount' => (float) $donation->getTotalPaidByDonor(),
            'totalCharityValueAmount' => (float) $donation->totalCharityValueAmount(),
            'transactionId' => $donation->getTransactionId(),
            'charityRegistrationAuthority' => $charity->getRegulatorName(),

            // There are other params that are currently sent from SF but not officially required by mailer.
            // These should be added before this function is used in production, but the data for them is not yet
            // available in matchbot DB. Params needed:
            //
            // - charityLogoUri
            // - charityPostalAddress
            // - charityPhoneNumber
            // - charityEmailAddress
            // - charityWebsite
            // - charityNumber
        ]);
    }
}
