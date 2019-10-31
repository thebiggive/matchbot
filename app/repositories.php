<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundRepository;
use Psr\Container\ContainerInterface;

return static function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        CampaignFundingRepository::class => static function (ContainerInterface $c): CampaignFundingRepository {
            return $c->get(EntityManagerInterface::class)->getRepository(CampaignFunding::class);
        },

        CampaignRepository::class => static function (ContainerInterface $c): CampaignRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Campaign::class);
            $repo->setClient($c->get(Client\Campaign::class));
            $repo->setFundRepository($c->get(FundRepository::class));

            return $repo;
        },

        FundRepository::class => static function (ContainerInterface $c): FundRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Fund::class);
            $repo->setClient($c->get(Client\Fund::class));
            $repo->setCampaignFundingRepository($c->get(CampaignFundingRepository::class));

            return $repo;
        }
    ]);
};
