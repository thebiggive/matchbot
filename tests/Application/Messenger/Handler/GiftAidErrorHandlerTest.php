<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\Handler\GiftAidErrorHandler;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;

class GiftAidErrorHandlerTest extends TestCase
{
    use DonationTestDataTrait;

    public function testErrorProcessing(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $testDonationPassedToProphecy = $this->getTestDonation();
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidErrorHandler = new GiftAidErrorHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
        );

        $donationMessage = $this->getTestDonation()->toClaimBotModel();
        $giftAidErrorHandler($donationMessage);

        // This is the one change to the passed Donation object we expect.
        $this->assertNotNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
    }
}
