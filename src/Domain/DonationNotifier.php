<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Client\Mailer;

class DonationNotifier
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - used by DI container
     */
    public function __construct(
        private Mailer $mailer
    ) {
    }

    public static function emailMessageForCollectedDonation(Donation $donation): EmailMessage
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

        return EmailMessage::donorDonationSuccess($emailAddress, [
            // see required params in mailer:
            // https://github.com/thebiggive/mailer/blob/ca2c70f10720a66ff8fb041d3af430a07f49d625/app/settings.php#L27
            'campaignName' => $campaign->getCampaignName(),
            'campaignThankYouMessage' => $campaign->getThankYouMessage(),
            'charityName' => $charity->getName(),
            'charityRegistrationAuthority' => $charity->getRegulatorName(),
            'charityNumber' => $charity->getRegulatorNumber(),

            // charityIsExempt is not yet used by mailer as it has its own logic
            // to work out if a charity is exempt. I'm hoping we can remove that soon.
            'charityIsExempt' => $charity->isExempt(),
            'currencyCode' => $donation->currency()->isoCode(),

            'donationAmount' => (float)$donation->getAmount(),
            'donationDatetime' => $collectedAt->format('c'),
            'donorFirstName' => $donation->getDonorFirstName(),
            'donorLastName' => $donation->getDonorLastName(),
            'giftAidAmountClaimed' => (float) $donation->getGiftAidValue(),

            'matchedAmount' => $donation->matchedAmount()->toMajorUnitFloat(),
            'paymentMethodType' => $paymentMethodType->value,
            'statementReference' => $charity->getStatementDescriptor(),
            'tipAmount' => (float) $donation->getTipAmount(),
            'totalChargedAmount' => (float) $donation->getTotalPaidByDonor(),

            'totalCharityValueAmount' => (float) $donation->totalCharityValueAmount(),
            'transactionId' => $donation->getTransactionId(),
            'charityLogoUri' => $charity->getLogoUri()?->__toString(),
            'charityWebsite' => $charity->getWebsiteUri()?->__toString(),

            'charityPhoneNumber' => $charity->getPhoneNumber(),
            'charityEmailAddress' => $charity->getEmailAddress()?->email,
            'charityPostalAddress' => $charity->getPostalAddress()->format(),

            // There are other params that are currently sent from SF but not officially required by mailer.
            // These should be added before this function is used in production, but the data for them is not yet
            // available in matchbot DB. Params needed:
            //
            // - charityEmailAddress
        ]);
    }

    public function notifyDonorOfDonationSuccess(Donation $donation): void
    {
        $this->mailer->send(self::emailMessageForCollectedDonation($donation));
    }
}
