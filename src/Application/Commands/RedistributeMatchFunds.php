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

/**
 * Redistribute match funding allocations where possible, from lower to higher priority match fund pots.
 */
class RedistributeMatchFunds extends LockingCommand // this script has stuff we want to additionally do.
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
        return $this->matchFundsRedistributor->redistributeMatchFunds($output);
    }
}
