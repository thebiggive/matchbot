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
    ): void {
        Assertion::eq($mandate->getCampaignId(), Salesforce18Id::ofCampaign($campaign->getSalesforceId()));

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
                    'campaignThankYouMessage' => '', // @todo-regular-giving: fix this and other empty strings.
                                                     // Either bring data proactively into matchbot (which I think
                                                     // is my preference) or we may have to delete this function
                                                     // and send from Salesforce instead.
                    'campaignThankYouEmail' => '',
                    'signupDate' => $signUpDate->format('d/m/Y H:i'),
                    'schedule' => $mandate->describeSchedule(),
                    'nextPaymentDate' => $mandate->firstPaymentDayAfter($this->clock->now())->format('d/m/Y'),
                    'amount' => $mandate->getAmount()->format(),
                    'giftAidValue' => '',   // @todo-regular-giving: think about where best to calculate GA value.
                                            // if government changes tax code after mandate started.
                    'totalIncGiftAd' => '',
                    'totalCharged' => $mandate->getAmount()->format(),
                    'charityPostalAddress' => '',
                    'charityPhoneNumber' => '',
                    'charityEMailAddress' => '',
                    'charityWebsite' => '',

                    'firstDonation' => [
                        // @todo-regular-giving: Fill in details of first donation from mandate, which should have
                        // been collected before we send this email. We will be forced to violate DRY since the existing
                        // equivalent for sending donation notifications is in SF not matchbot.
                    ]
                ],
            ]
        );
    }
}
