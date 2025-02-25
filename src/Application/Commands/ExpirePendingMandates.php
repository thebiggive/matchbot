<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\RegularGivingService;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:expire-pending-mandates',
    description: "Cancels regular giving mandates left pending (e.g. while donor considered 3DS authentication)",
)]
class ExpirePendingMandates extends LockingCommand
{
    public function __construct(
        private RegularGivingMandateRepository $regularGivingMandateRepository,
        private RegularGivingService $regularGivingService,
        private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $fiveMinutes = new \DateInterval('PT5M');
        $now = $this->clock->now();

        $mandatesToCancel = $this->regularGivingMandateRepository->findAllPendingSinceBefore(
            $now->sub($fiveMinutes)
        );

        foreach ($mandatesToCancel as $mandate) {
            $this->regularGivingService->cancelMandate(
                $mandate,
                'pending mandate expired at ' . $now->format('c') . ", donor may have walked away from 3DS",
                MandateCancellationType::FirstDonationUnsuccessful
            );
        };

        return 0;
    }
}
