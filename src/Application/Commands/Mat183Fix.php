<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Messenger\StripePayout;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * MAT-183 – a one-time script to sort out the status of donations on Payout po_1JPd8q4Fsxv9gTb66bdBPxOU
 * for Stripe account acct_1J3Iev4Fsxv9gTb6.
 *
 * These were left out of sync because of now-resolved bug MAT-181.
 */
class Mat183Fix extends LockingCommand
{
    protected static $defaultName = 'matchbot:mat-183-fix';

    private static string $payoutId = 'po_1JPd8q4Fsxv9gTb66bdBPxOU';
    private static string $stripeAccountId = 'acct_1J3Iev4Fsxv9gTb6';

    public function __construct(private RoutableMessageBus $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(
            "Puts a message on the queue to get the payout processed by the regular task."
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $message = (new StripePayout())
            ->setConnectAccountId(static::$stripeAccountId)
            ->setPayoutId(static::$payoutId);

        $stamps = [
            new BusNameStamp('stripe.payout.paid'),
            new TransportMessageIdStamp('payout.paid.' . static::$payoutId),
        ];

        try {
            $this->bus->dispatch(new Envelope($message, $stamps));
        } catch (TransportException $exception) {
            $output->writeln(sprintf(
                'Payout processing queue dispatch error %s.',
                $exception->getMessage(),
            ));

            return 1;
        }

        $output->writeln('Payout processing queue: MAT-183 fix message dispatched!');

        return 0;
    }
}
