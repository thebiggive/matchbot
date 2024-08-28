<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Brick\DateTime\Instant;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DomainException\NoDefaultPaymentMethod;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\FundingWithdrawalRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:take-regular-giving-donations',
    description: "Takes money from donors that they have given us advance permission to take.",
)]
class TakeRegularGivingDonations extends LockingCommand
{
    /** @psalm-suppress PossiblyUnusedMethod - called by PHP-DI */
    public function __construct(
        private \DateTimeImmutable $now,
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->createNewDonationsAccordingToRegularGivingMandates();
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate($output);

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(): void
    {
        // todo - implement.
        // Some details of how to actually create these donations are still to be worked out.
    }

    private function confirmPreCreatedDonationsThatHaveReachedPaymentDate(OutputInterface $output): void
    {
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($this->now, limit:20);

        foreach ($donations as $donation) {
            try {
                $this->donationService->confirmUsingDefaultPaymentMethod($donation);
                $output->writeln("Collected donation $donation");
            } catch (NoDefaultPaymentMethod $e) {
                $this->logger->warning($e->getMessage());
                $output->writeln($e->getMessage());

                $output->writeln(<<<'EOF'

                For now this is going to happen every time, as none of our donors have `default_source` set.
                Need to change the implementation of confirmUsingDefaultPaymentMethod to take a previously selected
                payment method ID from our database instead of from stripe. And probably rename to reflect that
                the PM will be one explicitly selected for this purpose, not just a default.
                
                EOF
                );
            }
        }
    }
}
