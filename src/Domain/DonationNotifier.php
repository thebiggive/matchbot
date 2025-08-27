<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Client\Mailer;

class DonationNotifier
{
    public function __construct(
        private Mailer $mailer,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
        private \DateTimeImmutable $now,
        private string $donateBaseUri,
    ) {
    }

    public static function emailMessageForCollectedDonation(
        Donation $donation,
        string $donateBaseUri,
        ?EmailVerificationToken $emailVerificationToken = null
    ): EmailMessage {
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

        $createAccountUri = null;
        if ($emailVerificationToken) {
            $personId = $donation->getDonorId();
            if ($personId) {
                $createAccountUri = sprintf(
                    '%s/register?c=%s&u=%s',
                    $donateBaseUri,
                    $emailVerificationToken->randomCode,
                    $personId->id,
                );
            }
        }

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

            'createAccountUri' => $createAccountUri,
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
        ]);
    }

    /**
     * Sends (Or resends) a donation thanks message to the donor of a donation. By default, uses the email
     * address and all other details as recorded on the donation, but if $to is passed the email is sent
     * to that address instead.
     *
     * @param Donation $donation
     * @param EmailAddress|null $to
     * @return void
     */
    public function notifyDonorOfDonationSuccess(
        Donation $donation,
        bool $sendRegisterUri,
        ?EmailAddress $to = null,
    ): void {
        $emailAddress = $donation->getDonorEmailAddress();
        Assertion::notNull($emailAddress);

        $emailVerificationToken = null;
        if ($sendRegisterUri) {
            $emailVerificationToken = $this->emailVerificationTokenRepository->findRecentTokenForEmailAddress(
                $emailAddress,
                $this->now,
            );
        }

        $emailMessage = self::emailMessageForCollectedDonation($donation, $this->donateBaseUri, $emailVerificationToken);

        if ($to !== null) {
            $emailMessage = $emailMessage->withToAddress($to);
        }

        $this->mailer->send($emailMessage);
    }
}
