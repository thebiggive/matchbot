<?php

namespace MatchBot\Tests\Application\Messenger\Handler;

use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Messenger\Handler\DonationStateUpdatedHandler;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Domain\DonationRepository;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;

class DonationStateUpdatedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
    }

    public function testItPushesOneDonationToSf(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, false)->shouldBeCalledOnce();

        $sut = new DonationStateUpdatedHandler(
            $this->donationRepositoryProphecy->reveal(),
            $this->createStub(RetrySafeEntityManager::class),
            new NullLogger(),
        );

        $message = DonationStateUpdated::fromDonation($donation);

        $sut->__invoke($message);
    }

    public function testItPushesDonationTwiceToSfWhenCreatedAndUpdated(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, true)->shouldBeCalledOnce();
        $this->donationRepositoryProphecy->push($donation, false)->shouldBeCalledOnce();

        $sut = new DonationStateUpdatedHandler(
            $this->donationRepositoryProphecy->reveal(),
            $this->createStub(RetrySafeEntityManager::class),
            new NullLogger(),
        );

        $sut->__invoke(DonationStateUpdated::fromDonation($donation, isNew: true));
        $sut->__invoke(DonationStateUpdated::fromDonation($donation));
    }

    /**
     * Messenger should automatically `nack()` the message as appropriate
     */
    public function testItThrowsIfDonationCannotBeFound(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn(null);
        $sut = new DonationStateUpdatedHandler(
            $this->donationRepositoryProphecy->reveal(),
            $this->createStub(RetrySafeEntityManager::class),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Donation not found');

        $sut->__invoke(DonationStateUpdated::fromDonation($donation));
    }

    /**
     * Messenger should automatically `nack()` the message as appropriate
     */
    public function testItThrowsIfDonationCannotBePushed(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, false)->willThrow(new \Exception('Failed to push to SF'));

        $sut = new DonationStateUpdatedHandler(
            $this->donationRepositoryProphecy->reveal(),
            $this->createStub(RetrySafeEntityManager::class),
            new NullLogger(),
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to push to SF');

        $sut->__invoke(DonationStateUpdated::fromDonation($donation));
    }
}
