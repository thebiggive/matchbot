<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\Handler\StripePayoutHandler;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\Application\StripeFormattingTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Service\BalanceTransactionService;
use Stripe\Service\ChargeService;
use Stripe\Service\PayoutService;
use Stripe\StripeClient;

/**
 * Note testSuccessfulUpdateFromFirstPayout method has moved to integration test class
 * {@see \MatchBot\IntegrationTests\StripePayoutHandlerTest}. Consider moving other tests for lower fragility.
 */
class StripePayoutHandlerTest extends TestCase
{
    use DonationTestDataTrait;
    use StripeFormattingTrait;

    private const string CONNECTED_ACCOUNT_ID = 'acct_unitTest123';
    private const string DEFAULT_PAYOUT_ID = 'po_externalId_123';
    private const string RETRIED_PAYOUT_ID = 'po_retrySuccess_234';

    public function testUnrecognisedChargeId(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_invalid');
        $chargeResponse = $this->getStripeHookMock('ApiResponse/ch_list_with_invalid');

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();

        // We call these once per whole loop, now we use txns, to ensure it's closed cleanly.
        $entityManagerProphecy->commit()->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => self::DEFAULT_PAYOUT_ID,
            ],
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection($balanceTxnResponse));

        $donationWithInvalidChargeId = clone $this->getTestDonation();

        $reflectionClass = new \ReflectionClass(Donation::class);
        $reflectionClass->getProperty('chargeId')->setValue($donationWithInvalidChargeId, 'ch_invalidId_123');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findWithTransferIdInArray(['tr_invalidId_123'])
            ->willReturn([$donationWithInvalidChargeId])
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->findAndLockOneBy(['chargeId' => 'ch_invalidId_123'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->info(
            'Payout: Getting all charges related to Payout ID po_externalId_123 for Connect account ID acct_unitTest123'
        )
            ->shouldBeCalledOnce();
        $loggerProphecy->info(
            'Payout: Getting all Connect account paid Charge IDs for Payout ID po_externalId_123 complete, found 1'
        )
            ->shouldBeCalledOnce();
        $loggerProphecy->info("Payout: Getting original TBG charge IDs related to payout's Charge IDs")
            ->shouldBeCalledOnce();
        $loggerProphecy->log('INFO', 'Payout: Donation not found with Charge ID ch_invalidId_123')
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Finished getting original Charge IDs, found 1 (from ' .
            '1 source transfer IDs and 1 donations whose transfer IDs matched)')
            ->shouldBeCalledOnce();
        $loggerProphecy->info(
            'Payout: Updating paid donations complete for stripe payout #po_externalId_123, persisted 0'
        )
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient(withRetriedPayout: false);
        // supressing deprecation notices for now on setting properties dynamically. Risk is low doing this in test
        // code, and may get mutation tests working again.
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();  // @phpstan-ignore property.notFound
        @$stripeClientProphecy->charges = $this->getStripeChargeList($chargeResponse);  // @phpstan-ignore property.notFound

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(LoggerInterface::class, $loggerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $this->invokePayoutHandler($container, $loggerProphecy->reveal());

        // Call count assertions above which include that the logger gets the expected
        // notice are all we need. No donation data changes in this scenario.
    }

    public function testInvalidExistingDonationStatus(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $balanceTxnsResponse = $this->getStripeHookMock('ApiResponse/bt_list_success');
        $chargeResponse = $this->getStripeHookMock('ApiResponse/ch_list_success');
        $donation = $this->getTestDonation();

        $donation->setDonationStatusForTest(DonationStatus::Failed);

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();

        // We call these once per whole loop, now we use txns, to ensure it's closed cleanly.
        $entityManagerProphecy->commit()->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => self::DEFAULT_PAYOUT_ID,
            ],
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection($balanceTxnsResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findWithTransferIdInArray(['tr_id_supplied_in_list_success'])
            ->willReturn([$donation])
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->findAndLockOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient(withRetriedPayout: false);
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();  // @phpstan-ignore property.notFound
        @$stripeClientProphecy->charges = $this->getStripeChargeList($chargeResponse);  // @phpstan-ignore property.notFound

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $this->invokePayoutHandler($container, new NullLogger());

        // We expect donations that are not in 'Collected' status to remain the same.
        $this->assertSame(DonationStatus::Failed, $donation->getDonationStatus());

        // Nothing to push to SF.
        $this->assertSame(SalesforceWriteProxy::PUSH_STATUS_COMPLETE, $donation->getSalesforcePushStatus());
    }

    /**
     * We expect this scenario when one payout that's directly linked to a charge
     * was created and failed, and then a subsequent payout succeeds. Stripe doesn't
     * *directly* list the retried charges in the balance_transactions list but does
     * list the payout which contains them.
     */
    public function testSuccessfulUpdateForRetriedPayout(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $donation = $this->getTestDonation();
        $chargeResponse = $this->getStripeHookMock('ApiResponse/ch_list_success');

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        // First call
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => self::RETRIED_PAYOUT_ID,
            ],
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection(
                $this->getStripeHookMock('ApiResponse/bt_list_only_retried_payout'),
            ));
        // Second call based on above mock's payout_failure source.
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => self::DEFAULT_PAYOUT_ID,
            ],
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection(
                $this->getStripeHookMock('ApiResponse/bt_list_success'),
            ));


        $donationRepository = $this->getReconcileMatchDonationRepo($donation);

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Donation::class)->willReturn($donationRepository);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient(withRetriedPayout: true);
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();  // @phpstan-ignore property.notFound
        @$stripeClientProphecy->charges = $this->getStripeChargeList($chargeResponse);  // @phpstan-ignore property.notFound

        $container->set(DonationRepository::class, $donationRepository);
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $this->invokePayoutHandler($container, new NullLogger(), self::RETRIED_PAYOUT_ID);

        $this->assertSame(DonationStatus::Paid, $donation->getDonationStatus());
        $this->assertSame(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE, $donation->getSalesforcePushStatus());
    }

    /**
     * This scenario is especially pertinent in March 2024 while we're planning use of a one-time script to patch
     * payouts from historic edge cases. But it's also good to cover the data sanity check for the long term, since
     * Stripe {@link https://docs.stripe.com/api/payouts/object#payout_object-status say} "Some payouts that fail might
     * initially show as paid, then change to failed."
     */
    public function testNoOpWhenPayoutFailed(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $donation = $this->getTestDonation();

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(Argument::cetera())
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Donation::class)->shouldNotBeCalled();
        $entityManagerProphecy->beginTransaction()->shouldNotBeCalled();

        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->warning(
            'Payout: Skipping payout ID po_externalId_123 for Connect account ID acct_unitTest123; status is failed'
        )
            ->shouldBeCalledOnce();
        $loggerProphecy->info(
            'Payout: Exited with no paid Charge IDs for Payout ID po_externalId_123, account acct_unitTest123',
        )
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient(withRetriedPayout: false, withPayoutSuccess: false);
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal(); // @phpstan-ignore property.notFound

        $container->set(DonationRepository::class, $this->prophesize(DonationRepository::class)->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(LoggerInterface::class, $loggerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $this->invokePayoutHandler($container, $loggerProphecy->reveal());

        // No change because payout's failed.
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
    }

    /**
     * Helper to return Prophecy of a Stripe client with its revealed prophesised properties that
     * *don't* vary between scenarios already set up.
     *
     * @return ObjectProphecy<StripeClient>
     */
    private function getStripeClient(bool $withRetriedPayout, bool $withPayoutSuccess = true): ObjectProphecy
    {
        $stripeClientProphecy = $this->prophesize(StripeClient::class);

        $stripePayoutProphecy = $this->prophesize(PayoutService::class);

        /** @var \stdClass $payoutMock */
        $payoutMock = json_decode($this->getStripeHookMock(
            $withPayoutSuccess ? 'ApiResponse/po' : 'ApiResponse/po_failed',
        ), false, 512, \JSON_THROW_ON_ERROR);

        if ($withRetriedPayout) {
            $stripePayoutProphecy->retrieve(
                self::RETRIED_PAYOUT_ID,
                null,
                ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
            )
                ->shouldBeCalledOnce()
                // This mock isn't very realistic as it has the other ID, but for this test
                // its properties except `status` aren't relevant.
                ->willReturn($payoutMock);
        }

        $stripePayoutProphecy->retrieve(
            self::DEFAULT_PAYOUT_ID,
            null,
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($payoutMock);

        // supressing deprecation notices for now on setting properties dynamically. Risk is low doing this in test
        // code, and may get mutation tests working again.
        @$stripeClientProphecy->payouts = $stripePayoutProphecy->reveal(); // @phpstan-ignore property.notFound

        return $stripeClientProphecy;
    }

    private function getCommonCalloutArgs(): array // @phpstan-ignore missingType.iterableValue
    {
        return [
            // Based on the date range from our standard test data payout (donation time -6M and +1D).
            'created' => [
                'gt' => 1582810856,
                'lt' => 1598622056,
            ],
            'limit' => 100
        ];
    }

    /**
     * Get a charges object with 'all' response as expected to reconcile against a donation.
     */
    private function getStripeChargeList(string $chargeResponse): ChargeService
    {
        $stripeChargeProphecy = $this->prophesize(ChargeService::class);
        $stripeChargeProphecy->all(
            $this->getCommonCalloutArgs(),
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection($chargeResponse));

        return $stripeChargeProphecy->reveal();
    }

    /**
     * Get a DonationRepository prophet expected to reconcile API calls against a donation.
     */
    private function getReconcileMatchDonationRepo(Donation $donation): DonationRepository
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findWithTransferIdInArray(['tr_id_supplied_in_list_success'])
            ->willReturn([$donation])
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->findAndLockOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->push(Argument::type(DonationUpserted::class));

        return $donationRepoProphecy->reveal();
    }

    /**
     * Manually invoke the handler, so we're not testing all the core Messenger Worker
     * & command that Symfony components' projects already test.
     */
    private function invokePayoutHandler(
        Container $container,
        LoggerInterface $logger,
        string $payoutId = self::DEFAULT_PAYOUT_ID,
    ): void {
        $payoutHandler = new StripePayoutHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $logger,
            $container->get(StripeClient::class)
        );

        $payoutMessage = new StripePayout(connectAccountId: self::CONNECTED_ACCOUNT_ID, payoutId: $payoutId);
        $payoutHandler($payoutMessage);
    }
}
