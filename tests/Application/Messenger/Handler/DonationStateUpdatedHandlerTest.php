<?php

namespace Application\Messenger\Handler;

use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Messenger\Handler\DonationStateUpdatedHandler;
use MatchBot\Domain\DonationRepository;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Messenger\Handler\Acknowledger;

class DonationStateUpdatedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    private bool $acknowledged = true;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
    }

    public function testItPushesOneDonationToSf(): void
    {
        $donation = \MatchBot\Tests\TestCase::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push($donation, false)->shouldBeCalledOnce();

        $sut = new DonationStateUpdatedHandler($this->donationRepositoryProphecy->reveal());

        $message = DonationStateUpdated::fromDonation($donation);

        $ack = new Acknowledger(DonationStateUpdatedHandler::class, $this->recieveAck(...));

        $sut->__invoke($message, $ack);
        $sut->flush(force: true);

        $this->assertTrue($this->acknowledged);
    }

    private function recieveAck(\Throwable|null $e, mixed $_result = null): void
    {
        if ($e !== null) {
            throw $e;
        }

        $this->acknowledged = true;
    }
}
