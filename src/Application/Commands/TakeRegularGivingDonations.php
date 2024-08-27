<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Brick\DateTime\Instant;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\FundingWithdrawalRepository;
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
    ) {
        parent::__construct();
    }
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->createNewDonationsAccordingToRegularGivingMandates();
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate();

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(): void
    {
        // todo - implement.
        // Some details of how to actually create these donations are still to be worked out.
    }

    private function confirmPreCreatedDonationsThatHaveReachedPaymentDate(): void
    {
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($this->now, limit:20);

        foreach ($donations as $donation) {
            // todo - replace stub card & payment method details or more likely remove those params.

            // todo - look up the default payment method ID for this donor.
            // I now think we do have to pass this and can't just rely on stripe auto
            // selecting the right method because our fees vary according to card details.
            $paymentMethodID = 'foo';
            $this->donationService->confirm($donation, $paymentMethodID);
        }
    }
}
