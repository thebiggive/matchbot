<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Messenger\StripePayout;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Reconcile donation statuses up to late Feb 2024 – one off to deal with previous payout edge cases.
 */
class ReconcilePastPayouts extends LockingCommand
{
    public const PAYOUT_INFO_CSV = <<<EOT
"acct_1IuusP3ZvDix6HgX","po_1LKu4v3ZvDix6HgXsVUHZ6pn"
EOT;

    protected static $defaultName = 'matchbot:reconcile-payouts';

    public function __construct(
        private RoutableMessageBus $bus,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Reconcile past donations from payout edge cases');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Roughly this~ https://www.php.net/manual/en/function.str-getcsv.php#101888
        $payouts = str_getcsv(self::PAYOUT_INFO_CSV, "\n");
        $payouts = array_map('str_getcsv', $payouts);

        $output->writeln(sprintf('Processing %d payouts...', count($payouts)));

        foreach ($payouts as $payout) {
            \assert(is_string($payout[0]));
            $payoutId = $payout[1];
            $message = (new StripePayout())
                ->setConnectAccountId($payout[0])
                ->setPayoutId($payoutId);

            $stamps = [
                new BusNameStamp(Event::PAYOUT_PAID),
                new TransportMessageIdStamp("payout.paid.$payoutId"),
            ];

            try {
                $this->bus->dispatch(new Envelope($message, $stamps));
            } catch (TransportException $exception) {
                $this->logger->error(sprintf(
                    'Payout processing queue dispatch via CLI error %s.',
                    $exception->getMessage(),
                ));

                return 1;
            }
        }

        $output->writeln('Completed past payout processing');

        return 0;
    }
}
