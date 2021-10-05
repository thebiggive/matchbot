<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\Handler\StripePayoutHandler;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Service\BalanceTransactionService;
use Stripe\Service\ChargeService;
use Stripe\Service\PayoutService;
use Stripe\Service\TransferService;
use Stripe\StripeClient;

class StripePayoutHandlerTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

    public function testUnrecognisedChargeId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_invalid');
        $chargeResponse = $this->getStripeHookMock('ApiResponse/ch_list_with_invalid');
        $transferResponse = $this->getStripeHookMock('ApiResponse/tr_list_with_invalid');

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => 'po_externalId_123',
                'type' => 'payment'
            ],
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));

        $stripeChargeProphecy = $this->prophesize(ChargeService::class);
        $stripeChargeProphecy->all(
            $this->getCommonCalloutArgs(),
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->all($this->getCommonCalloutArgs())
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($transferResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_invalidId_123'])
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
        $loggerProphecy->info('Payout: Donation not found with Charge ID ch_invalidId_123')
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Finished getting original Charge IDs, found 1')
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Updating paid donations complete, persisted 0')
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient();
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(LoggerInterface::class, $loggerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $payoutHandler = new StripePayoutHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            $loggerProphecy->reveal(),
            $container->get(StripeClient::class)
        );

        $payoutMessage = (new StripePayout())
            ->setConnectAccountId('acct_unitTest123')
            ->setPayoutId('po_externalId_123');
        $payoutHandler($payoutMessage);

        // Call count assertions above which include that the logger gets the expected
        // notice are all we need. No donation data changes in this scenario.
    }

    public function testInvalidExistingDonationStatus(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $balanceTxnsResponse = $this->getStripeHookMock('ApiResponse/bt_list_success');
        $chargeResponse = $this->getStripeHookMock('ApiResponse/ch_list_success');
        $transferResponse = $this->getStripeHookMock('ApiResponse/tr_list_success');
        $donation = $this->getTestDonation();

        $donation->setDonationStatus('Failed');

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => 'po_externalId_123',
                'type' => 'payment'
            ],
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnsResponse));

        $stripeChargeProphecy = $this->prophesize(ChargeService::class);
        $stripeChargeProphecy->all(
            $this->getCommonCalloutArgs(),
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->all($this->getCommonCalloutArgs())
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($transferResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient();
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $payoutHandler = new StripePayoutHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            new NullLogger(),
            $container->get(StripeClient::class)
        );

        $payoutMessage = (new StripePayout())
            ->setConnectAccountId('acct_unitTest123')
            ->setPayoutId('po_externalId_123');
        $payoutHandler($payoutMessage);

        // We expect donations that are not in 'Collected' status to remain the same.
        $this->assertEquals('Failed', $donation->getDonationStatus());
    }

    public function testSuccessfulUpdate(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $balanceTxnsResponse = $this->getStripeHookMock('ApiResponse/bt_list_success');
        $chargeResponse = $this->getStripeHookMock('ApiResponse/ch_list_success');
        $transferResponse = $this->getStripeHookMock('ApiResponse/tr_list_success');

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => 'po_externalId_123',
                'type' => 'payment',
            ],
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnsResponse));

        $stripeChargeProphecy = $this->prophesize(ChargeService::class);
        $stripeChargeProphecy->all(
            $this->getCommonCalloutArgs(),
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->all($this->getCommonCalloutArgs())
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($transferResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Donation::class)->willReturn($donationRepoProphecy->reveal());
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient();
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $payoutHandler = new StripePayoutHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            new NullLogger(),
            $container->get(StripeClient::class)
        );

        $payoutMessage = (new StripePayout())
            ->setConnectAccountId('acct_unitTest123')
            ->setPayoutId('po_externalId_123');
        $payoutHandler($payoutMessage);

        $this->assertEquals('Paid', $donation->getDonationStatus());
    }

    public function testSuccessfulUpdateWithBalanceTransactionsPagination(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $altDonation = $this->getAltTestDonation(); // Gets returned in `bt_list_success_with_more.json`.
        $donation = $this->getTestDonation();

        // To keep test data manageable, this response contains just 1 txn even though
        // we request 100 per page and it `has_more`.
        $balanceTxnsResponse1 = $this->getStripeHookMock('ApiResponse/bt_list_success_with_more');
        $balanceTxnsResponse2 = $this->getStripeHookMock('ApiResponse/bt_list_success');
        $chargeResponse1 = $this->getStripeHookMock('ApiResponse/ch_list_success_with_more');
        $chargeResponse2 = $this->getStripeHookMock('ApiResponse/ch_list_success');
        $transferResponse1 = $this->getStripeHookMock('ApiResponse/tr_list_success_with_more');
        $transferResponse2 = $this->getStripeHookMock('ApiResponse/tr_list_success');

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => 'po_externalId_123',
                'type' => 'payment',
            ],
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnsResponse1));
        $stripeBalanceTransactionProphecy->all(
            [
                'limit' => 100,
                'payout' => 'po_externalId_123',
                'type' => 'payment',
                'starting_after' => 'txn_2H4Rt9KkGuKkxwBNtVRZeh5w',
            ],
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnsResponse2));

        $stripeChargeProphecy = $this->prophesize(ChargeService::class);
        $stripeChargeProphecy->all(
            $this->getCommonCalloutArgs(),
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse1));

        $stripeChargeProphecy->all(
            array_merge($this->getCommonCalloutArgs(), ['starting_after' => 'ch_externalId_124']),
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse2));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->all($this->getCommonCalloutArgs())
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($transferResponse1));
        $stripeTransferProphecy
            ->all(array_merge($this->getCommonCalloutArgs(), ['starting_after' => 'tr_externalId_124']))
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($transferResponse2));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_124'])
            ->willReturn($altDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Donation::class)->willReturn($donationRepoProphecy->reveal());
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripeClientProphecy = $this->getStripeClient();
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        // Manually invoke the handler, so we're not testing all the core Messenger Worker
        // & command that Symfony components' projects already test.
        $payoutHandler = new StripePayoutHandler(
            $container->get(DonationRepository::class),
            $container->get(EntityManagerInterface::class),
            new NullLogger(),
            $container->get(StripeClient::class)
        );

        $payoutMessage = (new StripePayout())
            ->setConnectAccountId('acct_unitTest123')
            ->setPayoutId('po_externalId_123');
        $payoutHandler($payoutMessage);

        // Ensure both donations looked up are now Paid.
        $this->assertEquals('Paid', $altDonation->getDonationStatus());
        $this->assertEquals('Paid', $donation->getDonationStatus());
    }

    /**
     * Helper to return Prophecy of a Stripe client with its revealed prophesised properties that
     * *don't* vary between scenarios already set up.
     */
    protected function getStripeClient(): StripeClient|ObjectProphecy
    {
        $stripeClientProphecy = $this->prophesize(StripeClient::class);

        $stripePayoutProphecy = $this->prophesize(PayoutService::class);
        $stripePayoutProphecy->retrieve(
            'po_externalId_123',
            null,
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($this->getStripeHookMock('ApiResponse/po')));

        $stripeClientProphecy->payouts = $stripePayoutProphecy->reveal();

        return $stripeClientProphecy;
    }

    protected function getCommonCalloutArgs(): array
    {
        return [
            'created' => [ // Based on the date range from our standard test data payout (-22D, +1D).
                'gt' => 1596634856,
                'lt' => 1598622056
            ],
            'limit' => 100
        ];
    }
}
