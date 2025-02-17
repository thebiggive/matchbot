<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Assert\AssertionFailedException;
use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DomainException\RegularGivingDonationToOldToCollect;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\RegularGivingService;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'matchbot:collect-regular-giving',
    description: "Takes money from donors that they have given us advance permission to take.",
)]
class TakeRegularGivingDonations extends LockingCommand
{
    private const int MAXBATCHSIZE = 20;
    private ?RegularGivingService $mandateService = null;


    /** @psalm-suppress PossiblyUnusedMethod - called by PHP-DI */
    public function __construct(
        private Container $container,
        private RegularGivingMandateRepository $mandateRepository,
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private EntityManagerInterface $em,
        private Environment $environment,
        private LoggerInterface $logger,
    ) {
        parent::__construct();

        $this->addOption(
            'simulated-date',
            shortcut: 'simulated-date',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Simulated datetime'
        );
    }

    public function setSimulatedNow(string $simulateDateInput, OutputInterface $output): void
    {
        $simulatedNow = new \DateTimeImmutable($simulateDateInput);
        $this->container->set(\DateTimeImmutable::class, $simulatedNow);
        $output->writeln("Simulating running on {$simulatedNow->format('Y-m-d H:i:s')}");
    }

    /**
     * When we run this for manual testing on developer machines we will need to simulate a future time
     * instead of waiting for donations to become payable.
     */
    public function applySimulatedDate(?string $simulateDateInput, OutputInterface $output): void
    {
        switch (true) {
            case $this->environment !== Environment::Production && is_string($simulateDateInput):
                $this->setSimulatedNow($simulateDateInput, $output);
                break;
            case $this->environment === Environment::Production && is_string($simulateDateInput):
                throw new \Exception("Cannot simulate date in production");
            default:
                //no-op
        }

        $this->mandateService = $this->container->get(RegularGivingService::class);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @psalm-suppress MixedArgument */
        $this->applySimulatedDate($input->getOption('simulated-date'), $output);
        $now = $this->container->get(\DateTimeImmutable::class);

        $this->createNewDonationsAccordingToRegularGivingMandates($now, $io);
        $this->setPaymentIntentWhenReachedPaymentDate($now, $io);
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate($now, $io);

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(\DateTimeImmutable $now, SymfonyStyle $io): void
    {
        $mandates = $this->mandateRepository->findMandatesWithDonationsToCreateOn($now, self::MAXBATCHSIZE);

        $io->block(count($mandates) . " mandates may have donations to create at this time");

        foreach ($mandates as [$mandate]) {
            try {
                $donation = $this->makeDonationForMandate($mandate);
                if ($donation) {
                    $io->writeln("created donation {$donation}");
                } else {
                    $io->writeln("no donation created for {$mandate} as collection end date passed");
                }
            } catch (AssertionFailedException | WrongCampaignType $e) {
                $io->error($e->getMessage());
                $this->logger->error($e->getMessage());
            }
        }
    }

    private function setPaymentIntentWhenReachedPaymentDate(
        \DateTimeImmutable $now,
        SymfonyStyle $io
    ): void {
        $donations = $this->donationRepository->findDonationsToSetPaymentIntent($now, self::MAXBATCHSIZE);
        $io->block(count($donations) . " donations are due to have Payment Intent set at this time");

        foreach ($donations as $donation) {
            try {
                $this->donationService->createPaymentIntent($donation);
                $io->writeln("setting payment intent on donation #{$donation->getId()}");
            } catch (RegularGivingDonationToOldToCollect $e) {
                $this->logger->error($e->getMessage());
                $io->error($e->getMessage());
            }
        }
    }

    private function confirmPreCreatedDonationsThatHaveReachedPaymentDate(
        \DateTimeImmutable $now,
        SymfonyStyle $io
    ): void {
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($now, self::MAXBATCHSIZE);

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
                try {
                    $this->donationService->confirmPreAuthorized($donation);
                } catch (MandateNotActive $exception) {
                    $io->info($exception->getMessage());
                    continue;
                } catch (RegularGivingCollectionEndPassed) {
                    continue;
                }
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

    /**
     * @throws WrongCampaignType|AssertionFailedException
     */
    private function makeDonationForMandate(RegularGivingMandate $mandate): ?Donation
    {
        \assert($this->mandateService !== null);

        $donation = $this->mandateService->makeNextDonationForMandate($mandate);
        if ($donation) {
            $this->em->persist($donation);
        }

        $this->em->flush();

        return $donation;
    }
}
