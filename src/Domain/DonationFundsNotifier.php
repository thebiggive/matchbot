<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Client\Mailer;

class DonationFundsNotifier
{
    public function __construct(private Mailer $mailer)
    {
    }

    /**
     * Although we take the new balance as a param here since we thought it might be useful in development of this, we
     * don't actually do anything with that new balance. This is because we know that in many cases the balance will be
     * changing at almost exactly the same time that this email is generated, so the balance would be confusingly
     * outdated by the time the recipient got to read it.
     *
     * If they want to know their balance, they can look at "My Account" on the site.
     */
    public function notifyRecieptOfAccountFunds(
        DonorAccount $donorAccount,
        Money $transferAmount,
        Money $_newBalance,
    ): void {
        $donorName = $donorAccount->donorName;
        Assertion::notNull($donorName);

        /** @psalm-suppress DeprecatedMethod - method was deprecated after this was written. */
        $this->mailer->sendEmail([
            'templateKey' => 'donor-funds-thanks',
            'recipientEmailAddress' => $donorAccount->emailAddress->email,
            'params' => [
                'donorFirstName' => $donorName->first,
                'transferAmount' => $transferAmount->format(),
            ],
        ]);
    }
}
