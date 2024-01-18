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
use Stripe\Service\AccountService;
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
        // arrange
        $initDate = new \DateTimeImmutable('2023-04-10T00:00:00+0100');
        $previousDay = new \DateTimeImmutable('2023-04-09T00:00:00+0100');

        $testCustomerId = 'cus_aaaaaaaaaaaa11';
        $testPaymentMethodId = 'pm_aaaaaaaaaaaa13';

        $stripePaymentMethodsProphecy = $this->prophesize(PaymentMethodService::class);
        $stripePaymentMethodsProphecy->all([
            'customer' => $testCustomerId,
            'type' => 'card',
            'limit' => 100,
        ])
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/pm'),
            ));

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->search([
            'query' => "created<{$previousDay->getTimestamp()} and metadata['hasPasswordSince']:null " .
                "and metadata['paymentMethodsCleared']:null",
            'limit' => 100,
        ])
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/customer'),
            ));

        $stripeClientProphecy = $this->prophesize(StripeClient::class);

        // supressing deprecation notices for now on setting properties dynamically. Risk is low doing this in test
        // code, and may get mutation tests working again.
        @$stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        @$stripeClientProphecy->paymentMethods = $stripePaymentMethodsProphecy->reveal();

        /////////// delete when BG2-2548 done
        $accountService = $this->prophesize(AccountService::class);
        $accountService->allPersons(Argument::cetera())->willReturn([]);
        @$stripeClientProphecy->accounts = $accountService->reveal();
        ///////////

        $commandTester = new CommandTester($this->getCommand(
            $stripeClientProphecy,
            $initDate,
        ));

        // assert
        // One PM should be detached i.e. soft deleted.
        $stripePaymentMethodsProphecy->detach($testPaymentMethodId)->shouldBeCalledOnce();

        $stripeCustomersProphecy->update(
            $testCustomerId,
            ['metadata' => ['paymentMethodsCleared' => '2023-04-10 00:00:00']]
        )->shouldBeCalledOnce();

        // act
        $commandTester->execute([]);

        // assert
        $expectedOutputLines = [
            'matchbot:delete-stale-payment-details starting!',
            'Deleted 1 payment methods from Stripe, having checked 1 customers. Time Taken: 0s',
            'matchbot:delete-stale-payment-details complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    /**
     * @param ObjectProphecy<StripeClient> $stripeClientProphecy
     */
    private function getCommand(
        ObjectProphecy $stripeClientProphecy,
        \DateTimeImmutable $initDate,
    ): DeleteStalePaymentDetails {
        $stripeClient = $stripeClientProphecy->reveal();
        $command = new DeleteStalePaymentDetails($initDate, new NullLogger(), $stripeClient);
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
