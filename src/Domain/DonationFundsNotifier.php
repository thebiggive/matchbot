<?php

namespace MatchBot\Domain;

use MatchBot\Client\Mailer;
use MatchBot\Client\Stripe;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

class DonationFundsNotifier
{
    public function __construct(private Mailer $mailer, private Stripe $stripe, private ClockInterface $clock, private LoggerInterface $logger)
    {
    }

    public function notifyRecieptOfAccountFunds(DonorAccount $donorAccount, Money $transferAmount): void
    {
        $stripeAccountId = $donorAccount->stripeCustomerId;

        $this->waitForAnyTipToBeAutomaticallyPaidOut();
        $balance = $this->stripe->fetchBalance($stripeAccountId, $transferAmount->currency);

        $this->mailer->sendEmail([
            'templateKey' => 'donor-funds-thanks',
            'recipientEmailAddress' => $donorAccount->emailAddress->email,
            'params' => [
                'donorFirstName' => $donorAccount->donorName->first,
                'transferAmount' => $transferAmount->format(),
                'newBalance' => $balance->format(),
            ],
        ]);

        $id = $donorAccount->getId();
        \assert($id !== null);

        $this->logger->info(
            'Sent notification of receipt of account funds for Stripe Account: ' . $stripeAccountId->stripeCustomerId .
            ", transfer Amount" . $transferAmount->format() .
            ", new balance" . $balance->format() .
            ", DonorAccount #" . $id
        );
    }

    /**
     * A donor may have chosen to give us a tip when setting up their donation funds account. If it was a new account,
     * or an existing account but without sufficient fudns to complete that donation to us, then the donation would
     * not be taken out of their balance until they had enough available funds to cover it. As we know they just now
     * made a bank transfer to their account that time is likely to be now, so we wait for that donation to
     * complete to get an up-to-date account balance.
     *
     * Otherwise, we would tell them that they have x amount available, and then they would look at their account
     * seconds later and see that there is only x minus tip amount available, which could be confusing.
     */
    private function waitForAnyTipToBeAutomaticallyPaidOut(): void
    {
        $this->clock->sleep(seconds: 30);
    }
}
