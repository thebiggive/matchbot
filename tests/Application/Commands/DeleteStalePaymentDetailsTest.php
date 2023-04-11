<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\DeleteStalePaymentDetails;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\Application\StripeFormattingTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Stripe\Service\ChargeService;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class DeleteStalePaymentDetailsTest extends TestCase
{
    use DonationTestDataTrait;
    use StripeFormattingTrait;

    public function testDeletingOneStaleCard(): void
    {
        $initDate = new \DateTimeImmutable('now');
        $testCustomerId = 'cus_aaaaaaaaaaaa11';
        $testPmId = 'pm_aaaaaaaaaaaa13';

        $stripeChargesProphecy = $this->prophesize(ChargeService::class);
        $stripeChargesProphecy->all([
            'payment_method' => $testPmId,
            'status' => 'succeeded',
            'limit' => 100,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($this->buildEmptyCollection());

        $stripePaymentMethodsProphecy = $this->prophesize(PaymentMethodService::class);
        $stripePaymentMethodsProphecy->all([
            'customer' => $testCustomerId,
            'type' => 'card',
            'limit' => 100,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/pm'),
            ));

        // One PM should be detached i.e. soft deleted.
        $stripePaymentMethodsProphecy->detach($testPmId)
            ->shouldBeCalledOnce()
            ->willReturn($this->getStripeHookMock('ApiResponse/pm'));

        $oneDayBeforeTest = $initDate
            ->sub(new \DateInterval('P1D'))
            ->getTimestamp();

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->search([
            'query' => "created<$oneDayBeforeTest and metadata['hasPasswordSince']:null",
            'limit' => 100,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/customer'),
            ));

        $stripeClientProphecy = $this->prophesize(StripeClient::class);

        $stripeClientProphecy->charges = $stripeChargesProphecy->reveal();
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentMethods = $stripePaymentMethodsProphecy->reveal();

        $commandTester = new CommandTester($this->getCommand(
            $stripeClientProphecy,
            $initDate,
        ));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:delete-stale-payment-details starting!',
            'Deleted 1 payment methods from Stripe, having checked 1 customers',
            'matchbot:delete-stale-payment-details complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testNotDeletingOneUsedCard(): void
    {
        $initDate = new \DateTimeImmutable('now');
        $testCustomerId = 'cus_aaaaaaaaaaaa11';
        $testPmId = 'pm_aaaaaaaaaaaa13';

        $stripeChargesProphecy = $this->prophesize(ChargeService::class);
        $stripeChargesProphecy->all([
            'payment_method' => $testPmId,
            'status' => 'succeeded',
            'limit' => 100,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($this->buildCollectionFromSingleObjectFixture('ch_succeeded'));

        $stripePaymentMethodsProphecy = $this->prophesize(PaymentMethodService::class);
        $stripePaymentMethodsProphecy->all([
            'customer' => $testCustomerId,
            'type' => 'card',
            'limit' => 100,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/pm'),
            ));

        // *No* PM should be detached i.e. soft deleted, with any ID.
        $stripePaymentMethodsProphecy->detach(Argument::type('string'))
            ->shouldNotBeCalled();

        $oneDayBeforeTest = $initDate
            ->sub(new \DateInterval('P1D'))
            ->getTimestamp();

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->search([
            'query' => "created<$oneDayBeforeTest and metadata['hasPasswordSince']:null",
            'limit' => 100,
        ])
            ->shouldBeCalledOnce()
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/customer'),
            ));

        $stripeClientProphecy = $this->prophesize(StripeClient::class);

        $stripeClientProphecy->charges = $stripeChargesProphecy->reveal();
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentMethods = $stripePaymentMethodsProphecy->reveal();

        $commandTester = new CommandTester($this->getCommand(
            $stripeClientProphecy,
            $initDate,
        ));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:delete-stale-payment-details starting!',
            'Deleted 0 payment methods from Stripe, having checked 1 customers',
            'matchbot:delete-stale-payment-details complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function getCommand(
        ObjectProphecy $stripeClientProphecy,
        \DateTimeImmutable $initDate,
    ): DeleteStalePaymentDetails {
        $stripeClient = $stripeClientProphecy->reveal();
        \assert($stripeClient instanceof StripeClient);
        $command = new DeleteStalePaymentDetails($stripeClient, $initDate);
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
