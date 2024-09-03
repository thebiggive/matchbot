<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\MandateService;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        /** @psalm-suppress MixedArgument */
        $this->applySimulatedDate($input->getOption('simulated-date'), $output);

        $this->createNewDonationsAccordingToRegularGivingMandates();
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate($output);

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(): void
    {
        $mandates = $this->mandateRepository->findMandatesWithDonationsToCreateOn($this->now, limit: 20);

        foreach ($mandates as [$mandate]) {
            $this->makeDonationForMandate($mandate);
            $this->em->flush();
        }
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
            - Ensure we don't send emails that are meant for confirmation of on-session donations
            - Probably other things.
        */
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($this->now, limit:20);

        foreach ($donations as $donation) {
            $oldStatus = $donation->getDonationStatus();
            $output->writeln("processing donation $donation");
            try {
                $this->donationService->confirmPreAuthorized($donation);
            } catch (\Exception $exception) {
                $output->writeln('Exception, skipping donation: ' . $exception->getMessage());
            }
            $output->writeln(
                "Donation {$donation->getUuid()} went from " .
                "{$oldStatus->name} to {$donation->getDonationStatus()->name}"
            );
        }

        $this->em->flush();
    }

    private function makeDonationForMandate(RegularGivingMandate $mandate): void
    {
        $donation = $this->mandateService->makeNextDonationForMandate($mandate);
        $this->em->persist($donation);
        $this->em->flush();
    }
}
