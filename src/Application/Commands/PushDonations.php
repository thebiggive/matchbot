<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PushDonations extends LockingCommand
{
    protected static $defaultName = 'matchbot:push-donations';

    private DonationRepository $donationRepository;

    public function __construct(DonationRepository $donationRepository)
    {
        $this->donationRepository = $donationRepository;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Pushes details of any new or updated donations not yet synced to Salesforce');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $numberPushed = $this->donationRepository->pushAllPending();
        $output->writeln("Pushed $numberPushed donations to Salesforce");

        return 0;
    }
}
