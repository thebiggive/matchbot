<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\PushDonations;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\Factory as LockFactory;

$cliApp = new Application();

$commands = [
    new ExpireMatchFunds($psr11App->get(DonationRepository::class)),
    new PushDonations($psr11App->get(DonationRepository::class)),
    new RetrospectivelyMatch($psr11App->get(DonationRepository::class)),
    new UpdateCampaigns($psr11App->get(CampaignRepository::class), $psr11App->get(FundRepository::class)),
];
foreach ($commands as $command) {
    $command->setLockFactory($psr11App->get(LockFactory::class));
    $cliApp->add($command);
}

$cliApp->run();
