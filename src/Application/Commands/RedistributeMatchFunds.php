<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

class RedistributeMatchFunds extends LockingCommand
{
    protected static $defaultName = 'matchbot:redistribute-match-funds';

    public function __construct(
        private MatchFundsRedistributor $matchFundsRedistributor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Moves match funding allocations from lower to higher priority funds where possible');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        [$numberChecked, $donationsAmended] = $this->matchFundsRedistributor->redistributeMatchFunds();
        if ($donationsAmended > 0) {
            $output->writeln("Checked $numberChecked donations and redistributed matching for $donationsAmended");
        }

        return 0;
    }
}
