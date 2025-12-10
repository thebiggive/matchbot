<?php

declare(strict_types=1);

namespace MatchBot\IntegrationTests;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\Handler\StripePayoutHandler;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\Application\StripeFormattingTrait;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestLogger;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Stripe\Service\BalanceTransactionService;
use Stripe\Service\ChargeService;
use Stripe\Service\PayoutService;
use Stripe\StripeClient;

class StripePayoutHandlerTest extends IntegrationTest
{
    use DonationTestDataTrait;
    use StripeFormattingTrait;

    private const string CONNECTED_ACCOUNT_ID = 'acct_unitTest123';
    private const string DEFAULT_PAYOUT_ID = 'po_externalId_123';
    private const string RETRIED_PAYOUT_ID = 'po_retrySuccess_234';
    private TestLogger $testLogger;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $this->testLogger = new TestLogger();

        $this->getContainer()->set(LoggerInterface::class, $this->testLogger);
    }
    public function testSuccessfulUpdateFromFirstPayout(): void
    {
        $transferId = 'tr_' . TestCase::randomString();
        $donation = $this->getPersistedCollectedDonation(transferId: $transferId);
        $donationId = $donation->getId();
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => self::DEFAULT_PAYOUT_ID,
            ],
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection($this->getStripeHookMock('ApiResponse/bt_list_success')));

        // TODO remove this after CC25 mid-payout patching is done.
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->willReturn(
                /** @var \stdClass $btMock */
                json_decode(
                    $this->getStripeHookMock('ApiResponse/bt_success'),
                    false,
                    512,
                    \JSON_THROW_ON_ERROR
                )
            );

        $stripeClientProphecy = $this->getStripeClient(withRetriedPayout: false);
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal(); // @phpstan-ignore property.notFound
        @$stripeClientProphecy->charges = $this->getStripeChargeList($this->getStripeHookMock( // @phpstan-ignore property.notFound
            'ApiResponse/ch_list_success',
            dataOverrides: ['source_transfer' => $transferId]
        ));
        $this->getContainer()->set(StripeClient::class, $stripeClientProphecy->reveal());

        $this->getService(EntityManagerInterface::class)->clear();

        // act
        $this->invokePayoutHandler($this->getContainer());

        // assert
        $this->getService(EntityManagerInterface::class)->clear();

        // re-query for donation to check that SUT persisted it.
        $donationFetchedFromDB = $this->getService(EntityManagerInterface::class)
            ->find(Donation::class, $donationId);
        \assert($donationFetchedFromDB !== null);

        $this->assertSame(DonationStatus::Paid, $donationFetchedFromDB->getDonationStatus());
        $this->assertSame(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE, $donationFetchedFromDB->getSalesforcePushStatus());

        $this->assertSame(
            1598535656, // timestamp from po.json
            $donationFetchedFromDB->getPaidOutAt()?->getTimestamp()
        );

        $this->assertSame('po_externalId_123', $donationFetchedFromDB->getStripePayoutId());

        $this->assertSame(
            <<<"LOGS"
                info: Payout: Getting all charges related to Payout ID po_externalId_123 for Connect account ID acct_unitTest123
                info: Payout: Getting all Connect account paid Charge IDs for Payout ID po_externalId_123 complete, found 1
                info: Payout: Getting original TBG charge IDs related to payout's Charge IDs
                info: Payout: Finished getting original Charge IDs, found 1 (from 1 source transfer IDs and 1 donations whose transfer IDs matched)
                info: Payout: Corrected fee for donation {$donation->getUuid()} from 0.00 to 0.37 (from balance transaction txn_00000000000000)
                info: Marked donation ID {$donationId} paid based on stripe payout #po_externalId_123
                info: Payout: Updating paid donations complete for stripe payout #po_externalId_123, persisted 1
                
                LOGS,
            $this->testLogger->logString
        );
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
     *
     * (Temporarily also returns some charge on 'retrieve' too.)
     */
    private function getStripeChargeList(string $chargeResponse): ChargeService
    {
        $stripeChargeProphecy = $this->prophesize(ChargeService::class);

        // TODO remove this side effect after CC25 mid-payout patching is done.
        /** @var array{data: array<int, array<string, mixed>>} $chargeData */
        $chargeData = json_decode($chargeResponse, true, 512, \JSON_THROW_ON_ERROR);
        $stripeChargeProphecy->retrieve(Argument::type('string'))
            ->will(function () use ($chargeData) {
                $encodedCharge = json_encode($chargeData['data'][0], \JSON_THROW_ON_ERROR);
                /** @var \stdClass $chargeObj */
                $chargeObj = json_decode($encodedCharge, false, 512, \JSON_THROW_ON_ERROR);

                return $chargeObj;
            });

        $stripeChargeProphecy->all(
            $this->getCommonCalloutArgs(),
            ['stripe_account' => self::CONNECTED_ACCOUNT_ID],
        )
            ->shouldBeCalledOnce()
            ->willReturn($this->buildAutoIterableCollection($chargeResponse));

        return $stripeChargeProphecy->reveal();
    }

    /**
     * Manually invoke the handler, so we're not testing all the core Messenger Worker
     * & command that Symfony components' projects already test.
     */
    private function invokePayoutHandler(
        Container $container,
        string $payoutId = self::DEFAULT_PAYOUT_ID,
    ): void {
        $payoutHandler = $container->get(StripePayoutHandler::class);
        $payoutMessage = new StripePayout(connectAccountId: self::CONNECTED_ACCOUNT_ID, payoutId: $payoutId);
        $payoutHandler($payoutMessage);
    }

    public function getPersistedCollectedDonation(string $transferId): Donation
    {
        $donation = TestCase::someDonation(collected: true, transferId: $transferId);

        $this->getService(EntityManagerInterface::class)->persist($donation->getCampaign());
        $this->getService(EntityManagerInterface::class)->persist($donation);
        $this->getService(EntityManagerInterface::class)->flush();

        return $donation;
    }
}
