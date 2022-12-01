<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\Handler\GiftAidResultHandler;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

class GiftAidResultHandlerTest extends TestCase
{
    use DonationTestDataTrait;

    /**
     * Check that no Gift Aid fields are set if we just get back a message with no new relevant
     * metadata.
     */
    public function testNoOpProcessing(): void
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
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidErrorHandler = new GiftAidResultHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(LoggerInterface::class),
        );

        $donationMessage = $this->getTestDonation()->toClaimBotModel();
        $giftAidErrorHandler($donationMessage);

        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestConfirmedCompleteAt());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestCorrelationId());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidResponseDetail());
    }

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
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidErrorHandler = new GiftAidResultHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(LoggerInterface::class),
        );

        $donationMessage = $this->getTestDonation()->toClaimBotModel();
        $donationMessage->submission_correlation_id = 'failingCorrId';
        $donationMessage->response_success = false;
        $donationMessage->response_detail = 'Donation error deets';

        $giftAidErrorHandler($donationMessage);

        $this->assertInstanceOf(\DateTime::class, $testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
        $this->assertEquals('failingCorrId', $testDonationPassedToProphecy->getTbgGiftAidRequestCorrelationId());
        $this->assertEquals('Donation error deets', $testDonationPassedToProphecy->getTbgGiftAidResponseDetail());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestConfirmedCompleteAt());
    }

    public function testSuccessProcessing(): void
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
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidErrorHandler = new GiftAidResultHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(LoggerInterface::class),
        );

        $donationMessage = $this->getTestDonation()->toClaimBotModel();
        $donationMessage->submission_correlation_id = 'goodCorrId';
        $donationMessage->response_success = true;
        $donationMessage->response_detail = 'Thx for ur submission';

        $giftAidErrorHandler($donationMessage);

        $this->assertInstanceOf(
            \DateTime::class,
            $testDonationPassedToProphecy->getTbgGiftAidRequestConfirmedCompleteAt(),
        );
        $this->assertEquals('goodCorrId', $testDonationPassedToProphecy->getTbgGiftAidRequestCorrelationId());
        $this->assertEquals('Thx for ur submission', $testDonationPassedToProphecy->getTbgGiftAidResponseDetail());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
    }
}
