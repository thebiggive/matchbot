<?php

namespace MatchBot\Application\Commands;

use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

/**
 * @see RetrospectivelyMatch which does a similar thing for just-closed campaigns as one of the last campaign close tasks.
 */
#[AsCommand(
    name: 'matchbot:push-daily-fund-totals',
    description: 'Pushes champion funds used totals to Salesforce, for all associated with open campaigns'
)]
class PushDailyFundTotals extends LockingCommand
{
    public function __construct(
        private readonly FundRepository $fundRepository,
        private readonly RoutableMessageBus $bus,
    ) {
        parent::__construct();
    }

    public function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $funds = $this->fundRepository->findForCampaignsOpenAt(new \DateTimeImmutable('now'));
        foreach ($funds as $fund) {
            if ($fund->getFundType()->isPledge()) {
                // Skip pledges from daily total push as Salesforce doesn't yet do anything with that info
                // and it could be a lot of requests against a volume-limited Site.
                continue;
            }

            $this->bus->dispatch(new Envelope(FundTotalUpdated::fromFund($fund)));
        }

        $output->writeln(sprintf('Pushed %d fund totals to Salesforce for open campaigns', count($funds)));

        return 0;
    }
}
