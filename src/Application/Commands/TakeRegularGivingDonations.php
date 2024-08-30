<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Brick\DateTime\Instant;
use Doctrine\ORM\EntityManager;
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
        private EntityManager $em,
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
        /* Still to do to improve this before launch:
            - deal with possible "The parameter application_fee_amount cannot be updated on a PaymentIntent after a
              capture has already been made." error

            - Record unsuccessful payment attempts to limit number or time extent of retries
            - Stop collection if the related regular giving mandate has been cancelled
            - Send metadata to stripe so to identify the payment as regular giving when we view it there.
            - Handle exceptions and continue to next donation, e.g. if donation does not have customer ID, or there
              is no donor account in our db for that ID, or they do not have a payment method on file for this purpose.
            - Probably other things.
        */
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($this->now, limit:20);

        foreach ($donations as $donation) {
            $oldStatus = $donation->getDonationStatus();
            $output->writeln("processing donation $donation");
            $this->donationService->confirmPreAuthorized($donation);
            $output->writeln(
                "Donation {$donation->getUuid()} went from " .
                "{$oldStatus->name} to {$donation->getDonationStatus()->name}"
            );
        }

        $this->em->flush();
    }
}
