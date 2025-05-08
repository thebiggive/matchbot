<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\DeleteStalePaymentDetails;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\Application\StripeFormattingTrait;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData\Identity;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\Service\ChargeService;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class DeleteStalePaymentDetailsTest extends TestCase
{
    use DonationTestDataTrait;
    use StripeFormattingTrait;

    /** @var ObjectProphecy<DonorAccountRepository>  */
    private ObjectProphecy $donorAccountRepositoryProphecy;

    public function setUp(): void
    {
        parent::setUp();
        $this->donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);
    }

    public function testDeletingOneStaleCard(): void
    {
        // arrange
        $initDate = new \DateTimeImmutable('2023-04-10T00:00:00+0100');
        $previousDay = new \DateTimeImmutable('2023-04-09T00:00:00+0100');

        $testCustomerId = 'cus_aaaaaaaaaaaa11';
        $testPaymentMethodId = 'pm_aaaaaaaaaaaa13';

        $stripeClientProphecy = $this->prophesize(Stripe::class);

        $stripeClientProphecy->listAllPaymentMethodsForCustomer(
            StripeCustomerId::of($testCustomerId),
            [
            'type' => 'card',
            'limit' => 100,
            ]
        )
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/pm'),
            ));

        $stripeClientProphecy->searchCustomers([
            'query' => "created<{$previousDay->getTimestamp()} " .
                "and metadata['paymentMethodsCleared']:null",
            'limit' => 100,
        ])
            ->willReturn($this->buildCollectionFromSingleObjectFixture(
                $this->getStripeHookMock('ApiResponse/customer'),
            ));

        $commandTester = new CommandTester($this->getCommand(
            $stripeClientProphecy,
            $initDate,
        ));

        // One PM should be detached i.e. soft deleted.
        $stripeClientProphecy->detatchPaymentMethod(StripePaymentMethodId::of($testPaymentMethodId))->shouldBeCalledOnce();

        $stripeClientProphecy->updateCustomer(
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

    public function testItDetatchesAllMethodsFromACustomerWithNoPassword(): void
    {
        $stripeClientProphecy = $this->prophesize(Stripe::class);
        $customer = new Customer('cus_some_cust_id');
        $customer->metadata = new StripeObject();

        $paymentMethod1 = new PaymentMethod('pm_1');
        $paymentMethod1->allow_redisplay = 'always';

        $paymentMethod2 = new PaymentMethod('pm_2');
        $paymentMethod2->allow_redisplay = 'limited';

        $stripeClientProphecy->detatchPaymentMethod(StripePaymentMethodId::of('pm_1'))->shouldBeCalled();
        $stripeClientProphecy->detatchPaymentMethod(StripePaymentMethodId::of('pm_2'))->shouldBeCalled();

        $stripeClientProphecy->updateCustomer(
            "cus_some_cust_id",
            ["metadata" => ["paymentMethodsCleared" => "2025-01-01 00:00:00"]]
        )->shouldBeCalled();

        $sut = new DeleteStalePaymentDetails(
            new \DateTimeImmutable('2025-01-01 00:00:00'),
            new NullLogger(),
            $stripeClientProphecy->reveal(),
            $this->donorAccountRepositoryProphecy->reveal(),
        );

        $detatchedCount = $sut->detachStaleMethods(
            paymentMethods: [$paymentMethod1, $paymentMethod2],
            isDryRun: false,
            customer: $customer,
            donorAccount: null,
        );

        $this->assertSame(2, $detatchedCount);
    }

    public function testItDetatchesOnlyNonUsableMethodsFromACustomerWithPasswordSet(): void
    {
        $stripeClientProphecy = $this->prophesize(Stripe::class);
        $customer = new Customer('cus_some_cust_id');
        $customer->metadata = new StripeObject();

        // next line commented out because we failed to set this metadata for some customers in April and May
        // 2025, so we should not rely on it. See ID-52
        //
        // $customer->metadata['hasPasswordSince'] = 'any non null value';

        $donorAccount = Identity::donorAccount();
        $donorAccount->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_3'));

        $paymentMethod1 = new PaymentMethod('pm_1');
        $paymentMethod1->allow_redisplay = 'always';

        $paymentMethod2 = new PaymentMethod('pm_2');
        $paymentMethod2->allow_redisplay = 'limited';

        // this one is the customer's regular giving PM so should not be detatched.
        $paymentMethod3 = new PaymentMethod('pm_3');
        $paymentMethod3->allow_redisplay = 'limited';

        $stripeClientProphecy->detatchPaymentMethod(StripePaymentMethodId::of('pm_2'))->shouldBeCalled();

        $stripeClientProphecy->updateCustomer(
            "cus_some_cust_id",
            ["metadata" => ["paymentMethodsCleared" => "2025-01-01 00:00:00"]]
        )->shouldBeCalled();

        $sut = new DeleteStalePaymentDetails(
            new \DateTimeImmutable('2025-01-01 00:00:00'),
            new NullLogger(),
            $stripeClientProphecy->reveal(),
            $this->donorAccountRepositoryProphecy->reveal(),
        );

        $detatchedCount = $sut->detachStaleMethods(
            paymentMethods: [$paymentMethod1, $paymentMethod2, $paymentMethod3],
            isDryRun: false,
            customer: $customer,
            donorAccount: $donorAccount,
        );

        $this->assertSame(1, $detatchedCount);
    }

    /**
     * @param ObjectProphecy<Stripe> $stripeClientProphecy
     */
    private function getCommand(
        ObjectProphecy $stripeClientProphecy,
        \DateTimeImmutable $initDate,
    ): DeleteStalePaymentDetails {
        $stripeClient = $stripeClientProphecy->reveal();
        $command = new DeleteStalePaymentDetails($initDate, new NullLogger(), $stripeClient, $this->donorAccountRepositoryProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
