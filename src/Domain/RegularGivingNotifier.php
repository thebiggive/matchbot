<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Client\Mailer;
use Psr\Clock\ClockInterface;

class RegularGivingNotifier
{
    public function __construct(private readonly Mailer $mailer, private readonly ClockInterface $clock)
    {
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

        $this->mailer->sendEmail(
            [
                'templateKey' => 'donor-mandate-confirmation',
                'recipientEmailAddress' => $donorAccount->emailAddress->email,
                'params' => [
                    'charityName' => $charity->getName(),
                    'campaignName' => $campaign->getCampaignName(),
                    'charityNumber' => $charity->getRegulatorNumber(),
                    'campaignThankYouMessage' => $campaign->getThankYouMessage(),
                    'signupDate' => $signUpDate->format('d/m/Y H:i'),
                    'schedule' => $mandate->describeSchedule(),
                    'nextPaymentDate' => $mandate->firstPaymentDayAfter($this->clock->now())->format('d/m/Y'),
                    'amount' => $mandate->getAmount()->format(),
                    'giftAidValue' => $mandate->getGiftAidAmount()->format(),
                    'totalIncGiftAd' => $mandate->totalIncGiftAd()->format(),
                    'totalCharged' => $mandate->getAmount()->format(),
                    'firstDonation' => $this->donationToConfirmationEmailFields(
                        $firstDonation,
                        $charity,
                        $campaign
                    )
                ],
            ]
        );
    }

    /**
     * This is currently of course just for regular giving, but we may start using matchbot to send
     * ad-hoc donation email reciepts in future, in which case this function should be able to be moved
     * and re-used
     */
    private function donationToConfirmationEmailFields(
        Donation $firstDonation,
        Charity $charity,
        Campaign $campaign
    ): array {
        $firstDonationCollectedAt = $firstDonation->getCollectedAt();

        // @todo-regular-giving add assertion:
        // Assertion::notNull($firstDonationCollectedAt);
        // @see \MatchBot\Domain\RegularGivingService::setupNewMandate

        return [
            'currencyCode' => $firstDonation->getCurrencyCode(),
            'donationAmount' => $firstDonation->getAmount(),
            'donationDatetime' => $firstDonationCollectedAt?->format('c'),
            'charityName' => $charity->getName(),
            'transactionId' => $firstDonation->getTransactionId(),
            'matchedAmount' => $firstDonation->getFundingWithdrawalTotal(),
            'statementReference' => $campaign->getCharity()->getStatementDescriptor(),
            'giftAidAmountClaimed' => $firstDonation->getGiftAidValue(),
            'totalCharityValueAmount' => $firstDonation->totalCharityValueAmount()
        ];
    }
}
