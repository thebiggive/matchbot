<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Assert\AssertionFailedException;
use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\DomainException\PaymentIntentNotSucceeded;
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
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

#[AsCommand(
    name: 'matchbot:collect-regular-giving',
    description: "Takes money from donors that they have given us advance permission to take.",
)]
class TakeRegularGivingDonations extends LockingCommand
{
    private const int MAXBATCHSIZE = 20;

    private bool $reportableEventHappened = false;

    public function __construct(
        private Container $container,
        private RegularGivingMandateRepository $mandateRepository,
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private EntityManagerInterface $em,
        private Environment $environment,
        private LoggerInterface $logger,
        private RegularGivingService $mandateService,
        private ChatterInterface $chatter,
    ) {
        parent::__construct();

        $this->addOption(
            'simulated-date',
            shortcut: 'simulated-date',
            mode: InputOption::VALUE_REQUIRED,
            description: '(imperfectly) Simulated datetime - see comments in ' .  basename(__file__) . ' for details',
        );
    }

    /**
     * Note that only some usages of the system clock are currently replaced with a simulated date here, so results
     * may be inconsistent when using a simulated date. That's because matchbot-cli.php eagerly loads from the container
     * every service needed by every possible command at startup, so by this point DonationService and perhaps others
     * have already been created with a real system clock or timestamp.
     *
     * Consider using https://symfony.com/doc/current/console/lazy_commands.html or putting the simulated date in
     * container early in the matchbot-cli.php to fix.
     */
    public function setSimulatedNow(string $simulateDateInput, OutputInterface $output): void
    {
        $simulatedNow = new \DateTimeImmutable($simulateDateInput);
        $this->container->set(\DateTimeImmutable::class, $simulatedNow);
        $this->container->set(ClockInterface::class, new MockClock($simulatedNow));
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
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $bufferedOutput = new BufferedOutput();
        $io = new SymfonyStyle($input, $bufferedOutput);
        /** @psalm-suppress MixedArgument */
        $this->applySimulatedDate($input->getOption('simulated-date'), $bufferedOutput);
        $now = $this->container->get(\DateTimeImmutable::class);

        $this->createNewDonationsAccordingToRegularGivingMandates($now, $io);
        $this->setPaymentIntentWhenReachedPaymentDate($now, $io);
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate($now, $io);

        $outputText = $bufferedOutput->fetch();
        $output->writeln($outputText);

        // temporarily removed if condition below (and catch later) since sending report to Slack didn't seem to work
        // this morning when it should have been true and I want to see why.
        if ($this->reportableEventHappened) {
            $this->sendReport($this->truncate($outputText));
        }

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(\DateTimeImmutable $now, SymfonyStyle $io): void
    {
        $mandates = $this->mandateRepository->findMandatesWithDonationsToCreateOn($now, self::MAXBATCHSIZE);

        $mandateUUIDs = array_map(
            fn(array $mandate_charity) => $mandate_charity[0]->getUuid()->toString(),
            $mandates
        );

        $io->block(sprintf(
            "%s mandates may have donations to create at this time: %s",
            count($mandates),
            implode(', ', $mandateUUIDs)
        ));

        foreach ($mandates as [$mandate]) {
            try {
                $donation = $this->makeDonationForMandate($mandate);
                if ($donation) {
                    $this->reportableEventHappened = true;
                    $io->writeln("created donation {$donation}");
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
            $this->reportableEventHappened = true;
            try {
                $this->donationService->createAndAssociatePaymentIntent($donation);
                $io->writeln("setting payment intent on donation {$donation->getUuid()}");
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
            $this->reportableEventHappened = true;
            $preAuthDate = $donation->getPreAuthorizationDate();
            \assert($preAuthDate instanceof \DateTimeImmutable);
            $io->writeln("processing donation #{$donation->getId()}");
            $io->writeln(
                "Donation {$donation->getUuid()} is pre-authorized to pay on" .
                " <options=bold>{$preAuthDate->format('Y-m-d H:i:s')}</>
                "
            );
            try {
                try {
                    $this->donationService->confirmPreAuthorized($donation);
                    $io->writeln(
                        "Donation {$donation->getUuid()} is expected to become Collected when Stripe calls back"
                    );
                } catch (MandateNotActive $exception) {
                    $io->info($exception->getMessage());
                    continue;
                } catch (RegularGivingCollectionEndPassed $exception) {
                    $io->info($exception->getMessage());
                    continue;
                } catch (PaymentIntentNotSucceeded $exception) {
                    $io->error('PaymentIntentNotSucceeded, skipping donation: ' . $exception->getMessage());
                    continue;
                }
            } catch (\Exception $exception) {
                $io->error('Exception, skipping donation: ' . $exception->getMessage());
                continue;
            }
        }

        $this->em->flush();
    }

    /**
     * @throws WrongCampaignType|AssertionFailedException
     */
    private function makeDonationForMandate(RegularGivingMandate $mandate): ?Donation
    {
        $donation = $this->mandateService->makeNextDonationForMandate($mandate);
        if ($donation) {
            $this->em->persist($donation);
        }

        $this->em->flush();

        return $donation;
    }

    private function sendReport(string $outputText): void
    {
        if ($this->environment === Environment::Regression) {
            return;
        }

        $chatMessage = new ChatMessage('Regular giving collection report');

        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock(sprintf(
                '[%s] %s',
                $this->environment->name,
                'Regular giving collection report',
            ))))
            ->block((new SlackSectionBlock())->text($outputText));
        $chatMessage->options($options);

        $this->chatter->send($chatMessage);
    }

    /**
     * Truncates a string to a length we can send to Slack
     */
    private function truncate(string $string): string
    {
        return (strlen($string) > 3_000) ?
            substr($string, 0, 2_950) . "...\n\nReport truncated to fit in slack\n" :
            $string;
    }
}
