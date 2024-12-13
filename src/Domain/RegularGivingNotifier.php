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
            //                    'giftAidValue' => '',   // @todo-regular-giving: think about where best to calculate GA value.
            //                                            // if government changes tax code after mandate started.
            //                    'totalIncGiftAd' => '',
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

            // @todo-regular-giving - implement giftAidAmountClaimed. I think we want to store
            // this in the DB, not rely on being able to calculate in demand, to allow for the
            // tax rate to change.
            'giftAidAmountClaimed' => $firstDonation->getGiftAidValue(),
            //                           'totalWithGiftAid' => $firstDonation->totalWithGiftAid(),

            // @todo-regular-giving - implement totalCharityValueAmount as amount + total matched + gift aid
            //                    'totalCharityValueAmount' => $firstDonation->totalCharityValueAmount()

            // @todo-regular-giving: Fill in details of first donation from mandate, which should have
            // been collected before we send this email. We will be forced to violate DRY since the existing
            // equivalent for sending donation notifications is in SF not matchbot.
        ];
    }
}
