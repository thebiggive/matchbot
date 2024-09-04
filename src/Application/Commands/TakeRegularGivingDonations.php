<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\MandateService;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matchbot:take-regular-giving-donations',
    description: "Takes money from donors that they have given us advance permission to take.",
)]
class TakeRegularGivingDonations extends LockingCommand
{
    /** @psalm-suppress PossiblyUnusedMethod - called by PHP-DI */
    public function __construct(
        private \DateTimeImmutable $now,
        private RegularGivingMandateRepository $mandateRepository,
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private MandateService $mandateService,
        private EntityManagerInterface $em,
        private Environment $environment,
    ) {
        parent::__construct();

        $this->addOption(
            'simulated-date',
            shortcut: 'simulated-date',
            mode: InputOption::VALUE_REQUIRED,
            description: 'UUID of the donor in identity service'
        );
    }

    /**
     * When we run this for manual testing on developer machines we will need to simulate a future time
     * instead of waiting for donations to become payable.
     */
    public function applySimulatedDate(?string $simulateDateInput, OutputInterface $output): void
    {
        switch (true) {
            case $this->environment !== Environment::Production && is_string($simulateDateInput):
                $this->now = new \DateTimeImmutable($simulateDateInput);
                $output->writeln("Simulating running on {$this->now->format('Y-m-d H:i:s')}");
                break;
            case $this->environment === Environment::Production && is_string($simulateDateInput):
                throw new \Exception("Cannot simulate date in production");
            default:
                //no-op
        }
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @psalm-suppress MixedArgument */
        $this->applySimulatedDate($input->getOption('simulated-date'), $output);

        $this->createNewDonationsAccordingToRegularGivingMandates($io);
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate($io);

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(SymfonyStyle $io): void
    {
        $mandates = $this->mandateRepository->findMandatesWithDonationsToCreateOn($this->now, limit: 20);

        $io->block(count($mandates) . " mandates have donations to create at this time");

        foreach ($mandates as [$mandate]) {
            $donation = $this->makeDonationForMandate($mandate);
            $io->writeln("created donation {$donation}");
        }
    }

    private function confirmPreCreatedDonationsThatHaveReachedPaymentDate(SymfonyStyle $io): void
    {
        /* @todo-regular-giving
            Still to do to improve this before launch:
            - deal with possible "The parameter application_fee_amount cannot be updated on a PaymentIntent after a
              capture has already been made." error

            - Record unsuccessful payment attempts to limit number or time extent of retries
            - Stop collection if the related regular giving mandate has been cancelled
            - Send metadata to stripe so to identify the payment as regular giving when we view it there.
            - Handle exceptions and continue to next donation, e.g. if donation does not have customer ID, or there
              is no donor account in our db for that ID, or they do not have a payment method on file for this purpose.
            - Ensure we don't send emails that are meant for confirmation of on-session donations
            - Probably other things.
        */
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($this->now, limit:20);

        $io->block(count($donations) . " donations are due to be confirmed at this time");

        foreach ($donations as $donation) {
            $preAuthDate = $donation->getPreAuthorizationDate();
            \assert($preAuthDate instanceof \DateTimeImmutable);
            $io->writeln("processing donation #{$donation->getId()}");
            $io->writeln(
                "Donation #{$donation->getId()} is pre-authorized to pay on" .
                " <options=bold>{$preAuthDate->format('Y-m-d H:i:s')}</>}
                "
            );
            $oldStatus = $donation->getDonationStatus();
            try {
                $this->donationService->confirmPreAuthorized($donation);
            } catch (\Exception $exception) {
                $io->error('Exception, skipping donation: ' . $exception->getMessage());
                continue;
            }
            // status change not expected here - status will be changed by stripe callback to tell us its paid.
            $io->writeln(
                "Donation {$donation->getUuid()} went from " .
                "<options=bold>{$oldStatus->name}</> to <options=bold>{$donation->getDonationStatus()->name}</>"
            );
        }

        $this->em->flush();
    }

    private function makeDonationForMandate(RegularGivingMandate $mandate): Donation
    {
        $donation = $this->mandateService->makeNextDonationForMandate($mandate);
        $this->em->persist($donation);
        $this->em->flush();

        return $donation;
    }
}
