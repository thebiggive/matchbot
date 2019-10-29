<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use Psr\Container\ContainerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        CampaignRepository::class => function (ContainerInterface $c): CampaignRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Campaign::class);
            $repo->setClient($c->get(Client\Campaign::class));

            return $repo;
        },
    ]);
};
