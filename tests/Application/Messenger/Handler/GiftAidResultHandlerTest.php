<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\Handler\GiftAidResultHandler;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class GiftAidResultHandlerTest extends TestCase
{
    use DonationTestDataTrait;

    public const string DONATION_UUID = 'ae3aefc2-b405-11ef-8184-0718548b46e9';

    /**
     * Check that no Gift Aid fields are set if we just get back a message with no new relevant
     * metadata.
     */
    public function testNoOpProcessing(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $testDonationPassedToProphecy = $this->getTestDonation();
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUUID(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $this->getEntityManagerExpectingPersist());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidResultHandler = new GiftAidResultHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(LoggerInterface::class),
        );

        $donationMessage = $this->getTestDonation(uuid:Uuid::fromString(self::DONATION_UUID))
            ->toClaimBotModel();
        $giftAidResultHandler($donationMessage);

        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestConfirmedCompleteAt());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestCorrelationId());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidResponseDetail());
        $this->assertSame(
            SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE,
            $testDonationPassedToProphecy->getSalesforcePushStatus()
        );
    }

    public function testErrorProcessing(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $testDonationPassedToProphecy = $this->getTestDonation(uuid: Uuid::fromString(self::DONATION_UUID));
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUUID(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $this->getEntityManagerExpectingPersist());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidResultHandler = new GiftAidResultHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(LoggerInterface::class),
        );
        $donationMessage = $this->getTestDonation(uuid:Uuid::fromString(self::DONATION_UUID))
            ->toClaimBotModel();
        $donationMessage->submission_correlation_id = 'failingCorrId';
        $donationMessage->response_success = false;
        $donationMessage->response_detail = 'Donation error deets';

        $giftAidResultHandler($donationMessage);

        $this->assertInstanceOf(\DateTime::class, $testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
        $this->assertSame('failingCorrId', $testDonationPassedToProphecy->getTbgGiftAidRequestCorrelationId());
        $this->assertSame('Donation error deets', $testDonationPassedToProphecy->getTbgGiftAidResponseDetail());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestConfirmedCompleteAt());
    }

    public function testSuccessProcessing(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $testDonationPassedToProphecy = $this->getTestDonation(uuid: Uuid::fromString(self::DONATION_UUID));
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUUID(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($testDonationPassedToProphecy)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $this->getEntityManagerExpectingPersist());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $giftAidResultHandler = new GiftAidResultHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $container->get(LoggerInterface::class),
        );

        $donationMessage = $this->getTestDonation(uuid:Uuid::fromString(self::DONATION_UUID))->toClaimBotModel();
        $donationMessage->submission_correlation_id = 'goodCorrId';
        $donationMessage->response_success = true;
        $donationMessage->response_detail = 'Thx for ur submission';

        $giftAidResultHandler($donationMessage);

        $this->assertInstanceOf(
            \DateTime::class,
            $testDonationPassedToProphecy->getTbgGiftAidRequestConfirmedCompleteAt(),
        );
        $this->assertSame('goodCorrId', $testDonationPassedToProphecy->getTbgGiftAidRequestCorrelationId());
        $this->assertSame('Thx for ur submission', $testDonationPassedToProphecy->getTbgGiftAidResponseDetail());
        $this->assertNull($testDonationPassedToProphecy->getTbgGiftAidRequestFailedAt());
    }

    private function getEntityManagerExpectingPersist(): EntityManagerInterface
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        /**
         * @psalm-suppress MixedFunctionCall
         */
        $entityManagerProphecy->wrapInTransaction(Argument::type(\Closure::class))
            ->will(function (array $args): mixed {
                return $args[0]();
            })
            ->shouldBeCalledOnce();

        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        return $entityManagerProphecy->reveal();
    }
}
