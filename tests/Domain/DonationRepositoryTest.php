<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Client;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;

class DonationRepositoryTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

    public function testExistingPushOK(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn(true);

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertTrue($success);
    }

    public function testExistingPush404InSandbox(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willThrow(Client\NotFoundException::class);

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertTrue($success);
    }

    public function testError(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn(false);

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertFalse($success);
    }

    /**
     * @param ObjectProphecy|Client\Donation $donationClientProphecy
     * @return DonationRepository
     */
    private function getRepo(ObjectProphecy $donationClientProphecy): DonationRepository
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $repo = new DonationRepository($entityManagerProphecy->reveal(), new ClassMetadata(Donation::class));
        $repo->setClient($donationClientProphecy->reveal());
        $repo->setLogger(new NullLogger());

        return $repo;
    }
}
