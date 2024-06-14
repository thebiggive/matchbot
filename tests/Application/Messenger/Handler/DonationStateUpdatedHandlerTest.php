<?php

namespace MatchBot\Tests\Application\Messenger\Handler;

use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Messenger\Handler\DonationStateUpdatedHandler;
use MatchBot\Domain\DonationRepository;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Handler\Acknowledger;

class DonationStateUpdatedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    private bool $acknowledged = true;
    private ?\Throwable $exceptionFromLastAck;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
    }

    public function testItPushesOneDonationToSf(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, false)->shouldBeCalledOnce();

        $sut = new DonationStateUpdatedHandler($this->donationRepositoryProphecy->reveal(), new NullLogger());

        $message = DonationStateUpdated::fromDonation($donation);

        $sut->__invoke($message, $this->getAcknowledger());
        $sut->flush(force: true);

        $this->assertTrue($this->acknowledged);
    }

    public function testItPushesDonationOnceToSfWhenCreatedAndUpdated(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, true)->shouldBeCalledOnce();
        $this->donationRepositoryProphecy->push($donation, false)->shouldNotBeCalled();

        $sut = new DonationStateUpdatedHandler($this->donationRepositoryProphecy->reveal(), new NullLogger());

        $sut->__invoke(DonationStateUpdated::fromDonation($donation, isNew: true), $this->getAcknowledger());
        $sut->__invoke(DonationStateUpdated::fromDonation($donation), $this->getAcknowledger());
        $sut->flush(force: true);
    }

    public function testItNacksMessageIfDonationCannotBeFound(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn(null);
        $sut = new DonationStateUpdatedHandler($this->donationRepositoryProphecy->reveal(), new NullLogger());

        $sut->__invoke(DonationStateUpdated::fromDonation($donation), $this->getAcknowledger());
        $sut->flush(force: true);
        $this->assertNotNull($this->exceptionFromLastAck);
        $this->assertSame('Donation not found', $this->exceptionFromLastAck->getMessage());
    }

    public function testItNacksMessageIfDonationCannotBePushed(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, false)->willThrow(new \Exception('Failed to push to SF'));

        $sut = new DonationStateUpdatedHandler($this->donationRepositoryProphecy->reveal(), new NullLogger());

        $sut->__invoke(DonationStateUpdated::fromDonation($donation), $this->getAcknowledger());
        $sut->flush(force: true);
        $this->assertNotNull($this->exceptionFromLastAck);
        $this->assertSame('Failed to push to SF', $this->exceptionFromLastAck->getMessage());
    }


    public function getAcknowledger(): Acknowledger
    {
        return new Acknowledger(DonationStateUpdatedHandler::class, $this->recieveAck(...));
    }

    private function recieveAck(\Throwable|null $e, mixed $_result = null): void
    {
        $this->exceptionFromLastAck = $e;
        $this->acknowledged = true;
    }
}
