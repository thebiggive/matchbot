<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use MatchBot\Application\Commands\ReleaseLocks;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\Factory as LockFactory;

$cliApp = new Application();

$lockReleaseCommand = new ReleaseLocks();

$lockingCommands = [
    new UpdateCampaigns($psr11App->get(CampaignRepository::class), $psr11App->get(FundRepository::class)),
];
foreach ($lockingCommands as $lockingCommand) {
    // Inject database-backed lock store
    $lockingCommand->setLockFactory($psr11App->get(LockFactory::class));
    $lockReleaseCommand->addCommand($lockingCommand);
    $cliApp->add($lockingCommand);
}
$cliApp->add($lockReleaseCommand);

$cliApp->run();
