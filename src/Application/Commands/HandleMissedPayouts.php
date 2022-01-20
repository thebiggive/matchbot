<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * This command should be run once in Jan '22 then deleted. It will be invoked manually
 * and doesn't have test coverage.
 */
class HandleMissedPayouts extends LockingCommand
{
    protected static $defaultName = 'matchbot:handle-missed-payouts';

    public function __construct(
        private DonationRepository $donationRepository,
        private LoggerInterface $logger,
        private RoutableMessageBus $bus,
        private StripeClient $stripeClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(
            "Checks Stripe donations in Collected state up to late 2021 and marks them Paid if appropriate"
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Donation[] $donations */
        $donations = $this->donationRepository->findSuspiciousCollected();

        $numChecked = count($donations);
        $payoutsAdded = 0;

        $stripeAccountIdsToCheck = [];

        foreach ($donations as $donation) {
            $accountId = $donation->getCampaign()->getCharity()->getStripeAccountId();

            if (!in_array($accountId, $stripeAccountIdsToCheck, true)) {
                $stripeAccountIdsToCheck[] = $accountId;
            }
        }

        $toDate = new \DateTime('now');
        $fromDate = clone $toDate;
        $fromDate = $fromDate->sub(new \DateInterval('P365D'));

        foreach ($stripeAccountIdsToCheck as $connectAccountId) {
            $payouts = $this->stripeClient->payouts->all(
                [
                    'created' => [
                        'gt' => $fromDate->getTimestamp(),
                        'lt' => $toDate->getTimestamp(),
                    ],
                    'limit' => 100, // Should get them all in 1 page.
                ],
                ['stripe_account' => $connectAccountId],
            );

            foreach ($payouts as $payout) {
                if ($payout->status === 'paid') {
                    $message = (new StripePayout())
                        ->setConnectAccountId($connectAccountId)
                        ->setPayoutId($payout->id);

                    $stamps = [
                        new BusNameStamp('stripe.payout.paid'),
                        new TransportMessageIdStamp("payout.paid.{$payout->id}"),
                    ];

                    try {
                        $this->bus->dispatch(new Envelope($message, $stamps));
                    } catch (TransportException $exception) {
                        $this->logger->error(sprintf(
                            'Missed payout handler queue dispatch error %s.',
                            $exception->getMessage(),
                        ));
                    }

                    $payoutsAdded++;
                }
            }
        }

        $output->writeln(
            "Checked $numChecked donations. Added $payoutsAdded payouts for processing."
        );

        return 0;
    }
}
