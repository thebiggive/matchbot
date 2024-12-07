#!/usr/bin/env php
<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\CallFrequentTasks;
use MatchBot\Application\Commands\CancelStaleDonationFundTips;
use MatchBot\Application\Commands\ClaimGiftAid;
use MatchBot\Application\Commands\Command;
use MatchBot\Application\Commands\DeleteStalePaymentDetails;
use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Commands\LockingCommand;
use MatchBot\Application\Commands\PullIndividualCampaignFromSF;
use MatchBot\Application\Commands\PullMetaCampaignFromSF;
use MatchBot\Application\Commands\PushDonations;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\Commands\ResetMatching;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Commands\ReturnErroneousExcessFees;
use MatchBot\Application\Commands\ScheduledOutOfSyncFundsCheck;
use MatchBot\Application\Commands\SendStatistics;
use MatchBot\Application\Commands\SetupTestMandate;
use MatchBot\Application\Commands\TakeRegularGivingDonations;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Application\Matching;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\FundRepository;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stripe\StripeClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Notifier\ChatterInterface;


$messengerReceiverKey = 'receiver';
$messengerReceiverLocator = new Container();
$messengerReceiverLocator->set($messengerReceiverKey, $psr11App->get(TransportInterface::class));

$chatter = $psr11App->get(ChatterInterface::class);
assert($chatter instanceof ChatterInterface);

$dispatcher = new EventDispatcher();
$dispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleEvent $event) use ($psr11App) {
    $logger = $psr11App->get(Logger::class);
    $input = $event->getInput();

    if ($input->getOption(Command::CLI_OPTION_NOLOG)) {
        array_filter(
            $logger->getHandlers(),
            (static fn ($handler) => $handler instanceof StreamHandler)
        )[0]->setLevel(LogLevel::WARNING);
    }
});

$cliApp = new Application();
$cliApp->setDispatcher($dispatcher);
$cliApp->getDefinition()->addOption(
    new InputOption(
        Command::CLI_OPTION_NOLOG,
        'l',
        InputOption::VALUE_NONE,
        'Suppresses debug & info log, show only warnings and errors'
    )
);

/**
 * @psalm-suppress MixedArgument - too many of these to fix here. At some point we could fix on mass
 * by using a stub psr11 with generics. It's also not very important to fix for this statement as it is not called
 * inside any loop or conditional. If it's broken we'll know about it.
 */
$now = new \DateTimeImmutable('now');
$commands = [
    $psr11App->get(CallFrequentTasks::class),
    new ClaimGiftAid(
        $psr11App->get(DonationRepository::class),
        $psr11App->get(EntityManagerInterface::class),
        $psr11App->get(RoutableMessageBus::class),
    ),
    new ConsumeMessagesCommand(
        $psr11App->get(RoutableMessageBus::class),
        $messengerReceiverLocator,
        new EventDispatcher(),
        $psr11App->get(LoggerInterface::class),
        [$messengerReceiverKey],
    ),
    new DeleteStalePaymentDetails(
        $now,
        $psr11App->get(LoggerInterface::class),
        $psr11App->get(StripeClient::class),
    ),
    new ExpireMatchFunds($psr11App->get(DonationRepository::class)),
    $psr11App->get(HandleOutOfSyncFunds::class),
    new RedistributeMatchFunds(
        lockFactory: $psr11App->get(LockFactory::class),
        matchFundsRedistributor: $psr11App->get(MatchFundsRedistributor::class),
    ),
    new ScheduledOutOfSyncFundsCheck(
        $psr11App->get(CampaignFundingRepository::class),
        $psr11App->get(FundingWithdrawalRepository::class),
        $psr11App->get(Matching\Adapter::class),
        $chatter,
    ),
    new PushDonations(
        bus: $psr11App->get(RoutableMessageBus::class),
        now: $now,
        donationRepository: $psr11App->get(DonationRepository::class),
    ),
    new ResetMatching(
        $psr11App->get(CampaignFundingRepository::class),
        $psr11App->get(Matching\Adapter::class)
    ),
    new RetrospectivelyMatch(
        donationRepository: $psr11App->get(DonationRepository::class),
        matchFundsRedistributor: $psr11App->get(MatchFundsRedistributor::class),
        chatter: $chatter,
        bus: $psr11App->get(RoutableMessageBus::class),
        entityManager: $psr11App->get(EntityManagerInterface::class),
    ),
    new ReturnErroneousExcessFees(
        donationRepository: $psr11App->get(DonationRepository::class),
        logger: $psr11App->get(LoggerInterface::class),
        stripeClient: $psr11App->get(StripeClient::class),
    ),
    new UpdateCampaigns(
        $psr11App->get(CampaignRepository::class),
        $psr11App->get(EntityManagerInterface::class),
        $psr11App->get(FundRepository::class),
        $psr11App->get(LoggerInterface::class),
    ),
    $psr11App->get(SendStatistics::class),
    $psr11App->get(SetupTestMandate::class),
    $psr11App->get(TakeRegularGivingDonations::class),
    $psr11App->get(CancelStaleDonationFundTips::class),
    $psr11App->get(PullMetaCampaignFromSF::class),
    $psr11App->get(PullIndividualCampaignFromSF::class),
];

foreach ($commands as $command) {
    if ($command instanceof LockingCommand) { // i.e. not Symfony Messenger's built-in consumer.
        $command->setLockFactory($psr11App->get(LockFactory::class));
        $command->setLogger($psr11App->get(LoggerInterface::class));
    }

    $cliApp->add($command);
}


$cliApp->run();
