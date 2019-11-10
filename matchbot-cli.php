<?php

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Application;

$cliApp = new Application();

$cliApp->add(new UpdateCampaigns($psr11App->get(CampaignRepository::class), $psr11App->get(FundRepository::class)));

$cliApp->run();
