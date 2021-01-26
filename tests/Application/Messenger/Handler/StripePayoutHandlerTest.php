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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Service\BalanceTransactionService;
use Stripe\Service\ChargeService;
use Stripe\Service\TransferService;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class StripePayoutHandlerTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

    public function testUnrecognisedChargeId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $balanceTxnResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/bt_invalid.json'
        );
        $chargeResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/py_invalid.json'
        );
        $transferResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/tr_invalid.json'
        );

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
        $stripeChargeProphecy->retrieve(
            'py_invalidId_123',
            null,
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->retrieve('tr_invalidId_123')
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
        $loggerProphecy->info('Payout: Getting all Connect account paid Charge IDs complete, found 1')
            ->shouldBeCalledOnce();
        $loggerProphecy->info("Payout: Getting Transfer IDs related to payout's Charge IDs")
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Finished getting Charge-related Transfer IDs, found 1')
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Getting Charge Id from Transfer ID tr_invalidId_123')
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Donation not found with Charge ID ch_invalidId_123')
            ->shouldBeCalledOnce();
        $loggerProphecy->info('Payout: Updating paid donations complete, persisted 0')
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
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

        $balanceTxnResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/bt_success.json'
        );
        $chargeResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/py_success.json'
        );
        $transferResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/tr_success.json'
        );
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
            ->willReturn(json_decode($balanceTxnResponse));

        $stripeChargeProphecy = $this->prophesize(ChargeService::class);
        $stripeChargeProphecy->retrieve(
            'py_externalId_123',
            null,
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->retrieve('tr_externalId_123')
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($transferResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
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
        $balanceTxnResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/bt_success.json'
        );
        $chargeResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/py_success.json'
        );
        $transferResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/tr_success.json'
        );

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
        $stripeChargeProphecy->retrieve(
            'py_externalId_123',
            null,
            ['stripe_account' => 'acct_unitTest123'],
        )
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($chargeResponse));

        $stripeTransferProphecy = $this->prophesize(TransferService::class);
        $stripeTransferProphecy
            ->retrieve('tr_externalId_123')
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

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $stamps = [
            new BusNameStamp('stripe.payout.paid'),
            new TransportMessageIdStamp("payout.paid.po_externalId_123"),
        ];

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
}
