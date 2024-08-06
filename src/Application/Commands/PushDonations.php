<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\RoutableMessageBus;

class PushDonations extends LockingCommand
{
    protected static $defaultName = 'matchbot:push-donations';

    public function __construct(
        private RoutableMessageBus $bus,
        private \DateTimeImmutable $now,
        private DonationRepository $donationRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Pushes details of any new or updated donations not yet synced to Salesforce');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (($numberAbandoned = $this->donationRepository->abandonOldCancelled()) > 0) {
            $output->writeln("Abandoned $numberAbandoned old Cancelled donations from Salesforce push");
        }

        $numberPushed = $this->donationRepository->pushSalesforcePending(now: $this->now, bus: $this->bus);
        $output->writeln("Pushed $numberPushed donations to Salesforce");

        return 0;
    }
}
