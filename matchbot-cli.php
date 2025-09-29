#!/usr/bin/env php
<?php

declare(strict_types=1);

use MatchBot\Application\Commands\CallFrequentTasks;
use MatchBot\Application\Commands\CancelStaleDonationFundTips;
use MatchBot\Application\Commands\ClaimGiftAid;
use MatchBot\Application\Commands\Command;
use MatchBot\Application\Commands\CreateFictionalData;
use MatchBot\Application\Commands\DeleteOldTestFunds;
use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\ExpirePendingMandates;
use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Commands\LockingCommand;
use MatchBot\Application\Commands\MergeOpenApiDocs;
use MatchBot\Application\Commands\PullIndividualCampaignFromSF;
use MatchBot\Application\Commands\PullMetaCampaignFromSF;
use MatchBot\Application\Commands\PushDailyFundTotals;
use MatchBot\Application\Commands\PushDonations;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\Commands\ResetMatching;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Commands\ScheduledOutOfSyncFundsCheck;
use MatchBot\Application\Commands\SendStatistics;
use MatchBot\Application\Commands\SetupTestMandate;
use MatchBot\Application\Commands\TakeRegularGivingDonations;
use MatchBot\Application\Commands\UpdateCampaignDonationStats;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Application\Commands\WriteSchemaFile;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

$psr11App = require __DIR__ . '/bootstrap.php';

$commands = array_map($psr11App->get(...), [
    // Alphabetical list:
    CallFrequentTasks::class,
    CancelStaleDonationFundTips::class,
    ClaimGiftAid::class,
    ConsumeMessagesCommand::class,
    CreateFictionalData::class,
    DeleteOldTestFunds::class,
    ExpireMatchFunds::class,
    ExpirePendingMandates::class,
    HandleOutOfSyncFunds::class,
    MergeOpenApiDocs::class,
    PullIndividualCampaignFromSF::class,
    PullMetaCampaignFromSF::class,
    PushDailyFundTotals::class,
    PushDonations::class,
    RedistributeMatchFunds::class,
    ResetMatching::class,
    RetrospectivelyMatch::class,
    ScheduledOutOfSyncFundsCheck::class,
    SendStatistics::class,
    SetupTestMandate::class,
    TakeRegularGivingDonations::class,
    UpdateCampaignDonationStats::class,
    UpdateCampaigns::class,
    WriteSchemaFile::class,
]);

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
        null,
        InputOption::VALUE_NONE,
        'Suppresses debug & info log, show only warnings and errors'
    )
);

foreach ($commands as $command) {
    if ($command instanceof LockingCommand) { // i.e. not Symfony Messenger's built-in consumer.
        $command->setLockFactory($psr11App->get(LockFactory::class));
        $command->setLogger($psr11App->get(LoggerInterface::class));
    }

    $cliApp->add($command);
}



try {
    $cliApp->run();
} catch (Throwable $t) {
//    $logger = $psr11App->get(LoggerInterface::class);
//   $logger->error("CLI Error:" . $t->__toString());

    // not sure why that error message isn't appearing, so also sending this way:

    $slackConnction = $psr11App->get(ChatterInterface::class);
    $heading = "Matchbot CLI " . get_class($t);
    $chatMessage = new ChatMessage($heading);
    $options = (new SlackOptions())
            // For now, do a simple truncate at the max, 150 chars, since most messages are shorter and the next line
            // usually has the full text anyway.
            ->block((new SlackHeaderBlock(substr($heading, 0, 150))))
            // Text block is also limited to 3000 characters, so must truncate to not crash.
            ->block((new SlackSectionBlock())->text(substr($t->__toString(), 0, 3000)));
    $chatMessage->options($options);

    $slackConnction->send($chatMessage);

    throw $t;
}
