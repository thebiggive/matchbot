#!/usr/bin/env php
<?php

declare(strict_types=1);

use MatchBot\Application\Commands\Command;
use MatchBot\Application\Commands\LockingCommand;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;

$psr11App = require __DIR__ . '/bootstrap.php';

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

$commands = Command::allCommands($psr11App);
foreach ($commands as $command) {
    if ($command instanceof LockingCommand) { // i.e. not Symfony Messenger's built-in consumer.
        $command->setLockFactory($psr11App->get(LockFactory::class));
        $command->setLogger($psr11App->get(LoggerInterface::class));
    }

    $cliApp->add($command);
}


try {
    // We don't want Symfony to catch any throwable because we want to catch it ourselves and log it
    // instead (which should also mean it gets sent to Slack
    $cliApp->setCatchExceptions(false);
    $cliApp->setCatchErrors(false);
    $cliApp->run();
} catch (Throwable $t) {
    $logger = $psr11App->get(LoggerInterface::class);
    $logger->error("CLI Error: " . $t->__toString());

    $cliApp->renderThrowable($t, (new ConsoleOutput())->getErrorOutput());
    exit(1);
}
