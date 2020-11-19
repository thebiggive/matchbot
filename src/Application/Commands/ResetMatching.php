<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

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

    private CampaignFundingRepository $campaignFundingRepository;
    private Matching\Adapter $matchingAdapter;

    public function __construct(
        CampaignFundingRepository $campaignFundingRepository,
        Matching\Adapter $matchingAdapter
    )
    {
        $this->campaignFundingRepository = $campaignFundingRepository;
        $this->matchingAdapter = $matchingAdapter;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Delete fund balance data from the matching adapter');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (getenv('APP_ENV') === 'production') {
            // Erroring in one command stops the Composer script running the next one too, so this
            // should also eliminate the possibility of Composer `matchbot:reset` clearing the
            // Production database if run there in error.
            throw new \RuntimeException('Reset not supported on Production');
        }

        foreach ($this->campaignFundingRepository->findAll() as $funding) {
            $this->matchingAdapter->delete($funding);
        }

        return 0;
    }
}
