#!/usr/bin/env php
<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\ClaimGiftAid;
use MatchBot\Application\Commands\DeleteStalePaymentDetails;
use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Commands\LockingCommand;
use MatchBot\Application\Commands\PushDonations;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\Commands\ResetMatching;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Commands\ScheduledOutOfSyncFundsCheck;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\FundRepository;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\Console\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Notifier\ChatterInterface;

$cliApp = new Application();

$messengerReceiverKey = 'receiver';
$messengerReceiverLocator = new Container();
$messengerReceiverLocator->set($messengerReceiverKey, $psr11App->get(TransportInterface::class));

$chatter = $psr11App->get(ChatterInterface::class);
assert($chatter instanceof ChatterInterface);

/**
 * @psalm-suppress MixedArgument - too many of these to fix here. At some point we could fix on mass
 * by using a stub psr11 with generics. It's also not very important to fix for this statement as it is called inside
 * any loop or conditional. If it's broken we'll know about it.
 */
$now = new \DateTimeImmutable('now');
$commands = [
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
        $psr11App->get(CampaignFundingRepository::class),
        $now,
        $psr11App->get(DonationRepository::class),
        $psr11App->get(LoggerInterface::class),
    ),
    new ScheduledOutOfSyncFundsCheck(
        $psr11App->get(CampaignFundingRepository::class),
        $psr11App->get(EntityManagerInterface::class),
        $psr11App->get(FundingWithdrawalRepository::class),
        $psr11App->get(Matching\Adapter::class),
        $chatter,
    ),
    new PushDonations(now: $now, donationRepository: $psr11App->get(DonationRepository::class)),
    new ResetMatching(
        $psr11App->get(CampaignFundingRepository::class),
        $psr11App->get(Matching\Adapter::class)
    ),
    new RetrospectivelyMatch(
        $psr11App->get(DonationRepository::class),
        $chatter,
    ),
    new UpdateCampaigns(
        $psr11App->get(CampaignRepository::class),
        $psr11App->get(EntityManagerInterface::class),
        $psr11App->get(FundRepository::class),
        $psr11App->get(LoggerInterface::class),
    ),
];

foreach ($commands as $command) {
    if ($command instanceof LockingCommand) { // i.e. not Symfony Messenger's built-in consumer.
        $command->setLockFactory($psr11App->get(LockFactory::class));
        $command->setLogger($psr11App->get(LoggerInterface::class));
    }

    $cliApp->add($command);
}

$cliApp->run();
