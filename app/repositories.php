<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\FundRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

return static function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        CampaignFundingRepository::class => static function (ContainerInterface $c): CampaignFundingRepository {
            return $c->get(EntityManagerInterface::class)->getRepository(CampaignFunding::class);
        },

        CampaignRepository::class => static function (ContainerInterface $c): CampaignRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Campaign::class);
            $repo->setClient($c->get(Client\Campaign::class));
            $repo->setFundRepository($c->get(FundRepository::class));
            $repo->setLogger($c->get(LoggerInterface::class));

            return $repo;
        },

        DonationRepository::class => static function (ContainerInterface $c): DonationRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Donation::class);
            $repo->setCampaignRepository($c->get(CampaignRepository::class));
            $repo->setClient($c->get(Client\Donation::class));
            $repo->setFundRepository($c->get(FundRepository::class));
            $repo->setLockFactory($c->get(LockFactory::class));
            $repo->setLogger($c->get(LoggerInterface::class));
            $repo->setMatchingAdapter($c->get(Matching\Adapter::class));
            $repo->setSettings($c->get('settings'));

            return $repo;
        },

        FundRepository::class => static function (ContainerInterface $c): FundRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Fund::class);
            $repo->setClient($c->get(Client\Fund::class));
            $repo->setCampaignFundingRepository($c->get(CampaignFundingRepository::class));
            $repo->setLogger($c->get(LoggerInterface::class));
            $repo->setMatchingAdapter($c->get(Matching\Adapter::class));

            return $repo;
        },

        FundingWithdrawalRepository::class => static function (ContainerInterface $c): FundingWithdrawalRepository {
            return $c->get(EntityManagerInterface::class)->getRepository(FundingWithdrawal::class);
        }
    ]);
};
