<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Application\Messenger\DonationMatchingShouldBeChecked;
use MatchBot\Application\Messenger\Handler\DonationMatchCheckHandler;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;

class DonationMatchCheckHandlerTest extends TestCase
{
    use ProphecyTrait;

    public function testItAddsDonationUuidsToRedisSetAndReportsScard(): void
    {
        $jobUuid = (string) Uuid::uuid4();
        $donationUuid1 = (string) Uuid::uuid4();
        $donationUuid2 = (string) Uuid::uuid4();

        $message = new DonationMatchingShouldBeChecked(
            donationUuids: [$donationUuid1, $donationUuid2],
            retroMatchJobUuid: $jobUuid,
            includesCampaignsClosedSince: new \DateTimeImmutable('2026-01-01 00:00:00'),
            areFinalDonations: true,
        );

        $campaign = self::someCampaign(isMatched: true);
        $campaign->setId(42);

        $donation1 = self::someDonation(uuid: Uuid::fromString($donationUuid1), campaign: $campaign);
        $donation2 = self::someDonation(uuid: Uuid::fromString($donationUuid2), campaign: $campaign);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findByUuids([$donationUuid1, $donationUuid2])->willReturn([$donation1, $donation2]);

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->findForCampaignsClosedSince(
            Argument::type(\DateTimeImmutable::class),
            Argument::type(\DateTimeImmutable::class),
        )
            ->willReturn([]);

        $containerProphecy = $this->prophesize(ContainerInterface::class);
        $containerProphecy->get(DonationRepository::class)->willReturn($donationRepoProphecy->reveal());
        $containerProphecy->get(FundRepository::class)->willReturn($fundRepoProphecy->reveal());

        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->allocateMatchFunds($donation1)->willReturn('0.00');
        $allocatorProphecy->allocateMatchFunds($donation2)->willReturn('0.00');

        $redisProphecy = $this->prophesize(\Redis::class);
        $redisProphecy->sAdd("retro-match-{$jobUuid}-checkedDonationUuids", $donationUuid1)->shouldBeCalledOnce();
        $redisProphecy->sAdd("retro-match-{$jobUuid}-checkedDonationUuids", $donationUuid2)->shouldBeCalledOnce();
        $redisProphecy->incrBy("retro-match-{$jobUuid}-numAllocated", 0)->shouldBeCalledOnce();
        $redisProphecy->incrBy("retro-match-{$jobUuid}-penceAllocated", 0)->shouldBeCalledOnce();

        $redisProphecy->expire("retro-match-{$jobUuid}-checkedDonationUuids", 604800)->shouldBeCalledOnce();
        $redisProphecy->expire("retro-match-{$jobUuid}-numAllocated", 604800)->shouldBeCalledOnce();
        $redisProphecy->expire("retro-match-{$jobUuid}-penceAllocated", 604800)->shouldBeCalledOnce();
        $redisProphecy->expire("retro-match-{$jobUuid}-campaignIdsWithChanges", 604800)->shouldBeCalledOnce();

        $redisProphecy->scard("retro-match-{$jobUuid}-checkedDonationUuids")->willReturn(2)->shouldBeCalledOnce();
        $redisProphecy->get("retro-match-{$jobUuid}-numAllocated")->willReturn('0')->shouldBeCalledOnce();
        $redisProphecy->get("retro-match-{$jobUuid}-penceAllocated")->willReturn('0')->shouldBeCalledOnce();
        $redisProphecy->scard("retro-match-{$jobUuid}-campaignIdsWithChanges")->willReturn(0)->shouldBeCalledOnce();

        $redistributorProphecy = $this->prophesize(MatchFundsRedistributor::class);
        $redistributorProphecy->redistributeMatchFunds()->willReturn([0, 0])->shouldBeCalledOnce();

        $handler = new DonationMatchCheckHandler(
            allocator: $allocatorProphecy->reveal(),
            chatter: $this->prophesize(ChatterInterface::class)->reveal(),
            entityManager: $this->prophesize(EntityManagerInterface::class)->reveal(),
            environment: Environment::Test,
            logger: new NullLogger(),
            matchFundsRedistributor: $redistributorProphecy->reveal(),
            redis: $redisProphecy->reveal(),
            bus: $this->prophesize(RoutableMessageBus::class)->reveal(),
            container: $containerProphecy->reveal(),
        );

        $handler($message);
    }
}
