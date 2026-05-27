<?php

namespace MatchBot\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;

trait MakesDonationClient
{
    use ProphecyTrait;

    /**
     * @return ObjectProphecy<EntityManagerInterface>
     */
    public function prophesizeEM(): ObjectProphecy
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $this->mockRepo($entityManagerProphecy, \MatchBot\Domain\Donation::class, DonationRepository::class);
        $this->mockRepo($entityManagerProphecy, \MatchBot\Domain\Campaign::class, CampaignRepository::class);
        $this->mockRepo($entityManagerProphecy, \MatchBot\Domain\Charity::class, CharityRepository::class);
        $this->mockRepo($entityManagerProphecy, \MatchBot\Domain\Fund::class, FundRepository::class);
        $this->mockRepo($entityManagerProphecy, \MatchBot\Domain\FundingWithdrawal::class, FundingWithdrawalRepository::class);
        $this->mockRepo($entityManagerProphecy, \MatchBot\Domain\DonorAccount::class, DonorAccountRepository::class);
        $entityManagerProphecy->getRepository(\MatchBot\Domain\MetaCampaign::class)->willReturn($this->prophesize(EntityRepository::class)->reveal());
        $this->mockRepo($entityManagerProphecy, CampaignFundingRepository::class, CampaignFundingRepository::class);
        $this->mockRepo($entityManagerProphecy, CampaignFunding::class, CampaignFundingRepository::class);

        $entityManagerProphecy->beginTransaction()->will(function () {
        });
        $entityManagerProphecy->persist(Argument::any())->will(function () {
        });
        $entityManagerProphecy->flush()->will(function () {
        });
        $entityManagerProphecy->commit()->will(function () {
        });
        $entityManagerProphecy->rollback()->will(function () {
        });

        $configuration = $this->prophesize(Configuration::class);
        $entityManagerProphecy->getConfiguration()->willReturn($configuration->reveal());

        return $entityManagerProphecy;
    }

    /**
     * @template T of object
     * @param ObjectProphecy<EntityManagerInterface> $entityManagerProphecy
     * @param class-string $entityClass
     * @param class-string<T> $repoClass
     */
    private function mockRepo(
        ObjectProphecy $entityManagerProphecy,
        string $entityClass,
        string $repoClass
    ): void {
        $repoProphecy = $this->prophesize($repoClass);
        if ($repoClass !== DonationRepository::class) { // DonationRepository is not an EntityRepository
            $metaData = $this->prophesize(ClassMetadata::class);
            $revealedMetadata = $metaData->reveal();
            $revealedMetadata->name = $entityClass;
            $entityManagerProphecy->getClassMetadata($entityClass)->willReturn($revealedMetadata);

            $repoProphecy->willBeConstructedWith([
                $entityManagerProphecy->reveal(),
                $revealedMetadata,
            ]);
        }

        $entityManagerProphecy->getRepository($entityClass)->willReturn($repoProphecy->reveal());
    }
}
