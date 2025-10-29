<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Client\Mailer;
use Psr\Clock\ClockInterface;

class RegularGivingNotifier
{
    /** e.g. "29 October 2025, 11:47 GMT" */
    public const string TIMESTAMP_FORMAT = 'j F Y, H:i T';
    private \DateTimeZone $tz;
    public function __construct(
        private readonly Mailer $mailer,
        private readonly DonorAccountRepository $donorAccountRepository,
        private readonly ClockInterface $clock
    ) {
        $this->tz = new \DateTimeZone('Europe/London');
    }

    public function notifyNewMandateCreated(
        RegularGivingMandate $mandate,
        DonorAccount $donorAccount,
        Campaign $campaign,
        Donation $firstDonation,
    ): void {
        Assertion::eq($mandate->getCampaignId(), Salesforce18Id::ofCampaign($campaign->getSalesforceId()));
        Assertion::eq($firstDonation->getCampaign(), $campaign);
        Assertion::eq($firstDonation->getMandate(), $mandate);
        Assertion::eq(DonationSequenceNumber::of(1), $firstDonation->getMandateSequenceNumber());

        $charity = $campaign->getCharity();
        $signUpDate = $mandate->getActiveFrom();
        Assertion::notNull($signUpDate);

        $this->mailer->send(EmailMessage::donorMandateConfirmation(
            $donorAccount->emailAddress,
            [
                'donorName' => $donorAccount->donorName->fullName(),
                'charityName' => $charity->getName(),
                'campaignName' => $campaign->getCampaignName(),
                'charityNumber' => $charity->getRegulatorNumber(),
                'campaignThankYouMessage' => $campaign->getThankYouMessage(),
                'signupDate' => $signUpDate->setTimezone($this->tz)->format(self::TIMESTAMP_FORMAT),
                'schedule' => $mandate->describeSchedule(),
                'nextPaymentDate' => $mandate->firstPaymentDayAfter($this->clock->now())->setTimezone($this->tz)->format('j F Y'),
                'amount' => $mandate->getDonationAmount()->format(),
                'giftAidValue' => $mandate->getGiftAidAmount()->format(),
                'totalIncGiftAid' => $mandate->totalIncGiftAid()->format(),
                'totalCharged' => $mandate->getDonationAmount()->format(),
                'firstDonation' => $this->donationToConfirmationEmailFields(
                    $firstDonation,
                    $charity,
                    $campaign
                )
            ]
        ));
    }


    /**
     * Sends an email to notify donor that a payment failed collecting for this regular giving donation.
     *
     * @param Donation $donation - must be pre-authorized and linked to a regular giving mandate, and therefore also have donor account.
     */
    public function notifyCollectionFailed(Donation $donation, \DateTimeImmutable $at): void
    {
        $mandate = $donation->getMandate();
        Assertion::notNull($mandate);

        $donor = $this->donorAccountRepository->findByPersonId(
            $donation->getDonorId() ?? throw new \Exception('Donation missing Donor ID')
        );
        Assertion::notNull($donor);

        $preAuthDate = $donation->getPreAuthorizationDate();
        Assertion::notNull($preAuthDate);
        $preAuthDate = $preAuthDate->setTimezone($this->tz);

        $this->mailer->send(EmailMessage::donorRegularDonationFailed(
            $donor->emailAddress,
            [
                'donorName' => $donor->donorName->fullName(),
                'charityName' => $donation->getCampaign()->getCharity()->getName(),
                'amount' => Money::fromNumericString($donation->getAmount(), $donation->currency())->format(),
                'originalDonationPaymentDate' => $preAuthDate->format('j F Y'),
                'collectionAttemptTime' => $at->setTimezone($this->tz)->format(self::TIMESTAMP_FORMAT),
            ],
        ));
    }

    /**
     * This is currently of course just for regular giving, but we may start using matchbot to send
     * ad-hoc donation email reciepts in future, in which case this function should be able to be moved
     * and re-used
     *
     * @return array<string, string|null>
     */
    private function donationToConfirmationEmailFields(
        Donation $firstDonation,
        Charity $charity,
        Campaign $campaign
    ): array {
        $firstDonationCollectedAt = $firstDonation->getCollectedAt();

        Assertion::notNull($firstDonationCollectedAt, 'First donation collected at should not be null');

        return [
            'currencyCode' => $firstDonation->currency()->isoCode(),
            'donationAmount' => $firstDonation->getAmount(),
            'donationDatetime' => $firstDonationCollectedAt->format('c'),
            'charityName' => $charity->getName(),
            'transactionId' => $firstDonation->getTransactionId(),
            'matchedAmount' => $firstDonation->getFundingWithdrawalTotal(),
            'statementReference' => $campaign->getCharity()->getStatementDescriptor(),
            'giftAidAmountClaimed' => $firstDonation->getGiftAidValue(),
            'totalCharityValueAmount' => $firstDonation->totalCharityValueAmount()
        ];
    }
}
