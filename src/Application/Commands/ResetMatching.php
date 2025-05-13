<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\DBAL\Exception\TableNotFoundException;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFundingRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete all fund data from the matching adapter. Typically called immediately prior to a full
 * Doctrine database reset, via the Composer `matchbot:reset` script.
 */
class ResetMatching extends LockingCommand
{
    protected static $defaultName = 'matchbot:reset-matching';

    public function __construct(
        private CampaignFundingRepository $campaignFundingRepository,
        private Matching\Adapter $matchingAdapter
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Delete fund balance data from the matching adapter');
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (getenv('APP_ENV') === 'production') {
            // Erroring in one command stops the Composer script running the next one too, so this
            // should also eliminate the possibility of Composer `matchbot:reset` clearing the
            // Production database if run there in error.
            throw new \RuntimeException('Reset not supported on Production');
        }

        try {
            $fundings = $this->campaignFundingRepository->findAll();
        } catch (TableNotFoundException $exception) {
            $output->writeln('Skipping matching reset as database is empty.');

            return 0;
        }

        foreach ($fundings as $funding) {
            $this->matchingAdapter->delete($funding);
        }
        $output->writeln(sprintf('Completed matching reset for %d fundings.', count($fundings)));

        return 0;
    }
}
