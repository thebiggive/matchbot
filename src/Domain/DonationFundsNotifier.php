<?php

namespace MatchBot\Domain;

use MatchBot\Client\Mailer;

class DonationFundsNotifier
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function notifyRecieptOfAccountFunds(DonorAccount $donorAccount, Money $transferAmount, Money $newBalance): void
    {
        $this->mailer->sendEmail([
            'templateKey' => 'donor-funds-thanks',
            'recipientEmailAddress' => $donorAccount->emailAddress->email,
            'params' => [
                'donorFirstName' => $donorAccount->donorName->first,
                'transferAmount' => $transferAmount->format(),
                'newBalance' => $newBalance->format(),
            ],
        ]);
    }
}