<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Commands\PushDonations;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\LockFactory;

$cliApp = new Application();

$commands = [
    new ExpireMatchFunds($psr11App->get(DonationRepository::class)),
    new HandleOutOfSyncFunds(
        $psr11App->get(CampaignFundingRepository::class),
        $psr11App->get(FundingWithdrawalRepository::class),
        $psr11App->get(Matching\Adapter::class)
    ),
    new PushDonations($psr11App->get(DonationRepository::class)),
    new RetrospectivelyMatch($psr11App->get(DonationRepository::class)),
    new UpdateCampaigns(
        $psr11App->get(CampaignRepository::class),
        $psr11App->get(EntityManagerInterface::class),
        $psr11App->get(FundRepository::class),
    ),
];
foreach ($commands as $command) {
    $command->setLockFactory($psr11App->get(LockFactory::class));
    $cliApp->add($command);
}

$cliApp->run();
