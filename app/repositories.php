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
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\RegularGivingMandateRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

return static function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        CampaignFundingRepository::class => static function (ContainerInterface $c): CampaignFundingRepository {
            $repository = $c->get(EntityManagerInterface::class)->getRepository(CampaignFunding::class);
            \assert($repository instanceof CampaignFundingRepository);

            return $repository;
        },

        CampaignRepository::class => static function (ContainerInterface $c): CampaignRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Campaign::class);
            \assert($repo instanceof CampaignRepository);

            $repo->setClient($c->get(Client\Campaign::class));
            $repo->setLogger($c->get(LoggerInterface::class));
            $repo->setFundRepository($c->get(FundRepository::class));
            $repo->setClock($c->get(ClockInterface::class));

            return $repo;
        },

        CharityRepository::class => static function (ContainerInterface $c): CharityRepository {
            $repository = $c->get(EntityManagerInterface::class)->getRepository(Charity::class);
            \assert($repository instanceof CharityRepository);

            return $repository;
        },

        DonationRepository::class => static function (ContainerInterface $c): DonationRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Donation::class);

            \assert($repo instanceof DoctrineDonationRepository);

            $repo->setClient($c->get(Client\Donation::class));
            $repo->setLogger($c->get(LoggerInterface::class));

            return $repo;
        },

        FundRepository::class => static function (ContainerInterface $c): FundRepository {
            $repo = $c->get(EntityManagerInterface::class)->getRepository(Fund::class);
            assert($repo instanceof FundRepository);

            $repo->setClient($c->get(Client\Fund::class));
            $repo->setCampaignFundingRepository($c->get(CampaignFundingRepository::class));
            $repo->setLogger($c->get(LoggerInterface::class));
            $repo->setMatchingAdapter($c->get(Matching\Adapter::class));


            return $repo;
        },

        FundingWithdrawalRepository::class => static function (ContainerInterface $c): FundingWithdrawalRepository {
            $repository = $c->get(EntityManagerInterface::class)->getRepository(FundingWithdrawal::class);
            \assert($repository instanceof FundingWithdrawalRepository);
            return $repository;
        },

        DonorAccountRepository::class => static function (ContainerInterface $c): DonorAccountRepository {
            $em = $c->get(EntityManagerInterface::class);
            \assert($em instanceof EntityManagerInterface);
            $repo = $em->getRepository(DonorAccount::class);
            \assert($repo instanceof DonorAccountRepository);
            return $repo;
        },

        RegularGivingMandateRepository::class =>
            static fn(ContainerInterface $c): RegularGivingMandateRepository => new RegularGivingMandateRepository(
                $c->get(EntityManagerInterface::class)
            ),

        MetaCampaignRepository::class => static fn(ContainerInterface $c): MetaCampaignRepository => new MetaCampaignRepository(
            $c->get(EntityManagerInterface::class)
        ),
    ]);
};
