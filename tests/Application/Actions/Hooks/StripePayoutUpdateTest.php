<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Stripe\Service\BalanceTransactionService;
use Stripe\Service\ChargeService;
use Stripe\Service\TransferService;
use Stripe\StripeClient;

class StripePayoutUpdateTest extends StripeTest
{
    use ProphecyTrait;

    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // Payment Intent events, including cancellations, return a 204 No Content no-op for now.
        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/pi_canceled.json');
        $webhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testMissingSignature(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/ch_succeeded.json');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', '');

        $response = $app->handle($request);

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Invalid Signature',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $payload = (string) $response->getBody();

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testUnrecognisedChargeIdPayout(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/po_paid.json');
        $balanceTxnResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/bt_invalid.json'
        );
        $chargeResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/py_invalid.json'
        );
        $transferResponse = file_get_contents(
            dirname(__DIR__, 3) . '/TestData/StripeWebhook/ApiResponse/tr_invalid.json'
        );
        $webhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

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

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testInvalidStatusPayout(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/po_paid.json');
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
        $webhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];
        $time = (string) time();

        $donation->setDonationStatus('Failed');

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

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

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // Despite handling Stripe payout logic, we expect donations
        // that are not in 'Collected' status to remain the same.
        $this->assertEquals('Failed', $donation->getDonationStatus());
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSuccessfulPayout(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/po_paid.json');
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
        $webhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

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

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();
        $stripeClientProphecy->charges = $stripeChargeProphecy->reveal();
        $stripeClientProphecy->transfers = $stripeTransferProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('Paid', $donation->getDonationStatus());
        $this->assertEquals(200, $response->getStatusCode());
    }
}
