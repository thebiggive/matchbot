<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use MatchBot\Application\Commands\LockingCommand;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\Factory as LockFactory;

$cliApp = new Application();

$commands = [
    new UpdateCampaigns($psr11App->get(CampaignRepository::class), $psr11App->get(FundRepository::class)),
];
foreach ($commands as $command) {
    if ($command instanceof LockingCommand) {
        // Inject database-backed lock store
        $command->setLockFactory($psr11App->get(LockFactory::class));
    }

    $cliApp->add($command);
}

$cliApp->run();
