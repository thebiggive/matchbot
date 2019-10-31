<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundRepository;
use Psr\Container\ContainerInterface;

return static function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        CampaignRepository::class => static function (ContainerInterface $c): CampaignRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Campaign::class);
            $repo->setClient($c->get(Client\Campaign::class));
            $repo->setFundRepository($c->get(FundRepository::class));

            return $repo;
        },

        FundRepository::class => static function (ContainerInterface $c): FundRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Fund::class);
            $repo->setClient($c->get(Client\Fund::class));

            return $repo;
        }
    ]);
};
