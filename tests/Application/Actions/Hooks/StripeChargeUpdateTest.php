<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Prophecy\Argument;
use Stripe\Service\BalanceTransactionService;
use Stripe\StripeClient;

class StripeChargeUpdateTest extends StripeTest
{
    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // Payment Intent events, including cancellations, return a 204 No Content no-op for now.
        $body = $this->getStripeHookMock('pi_canceled');
        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testUnrecognisedTransactionId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_invalid_id');
        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['transactionId' => 'pi_invalidId_123'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testMissingSignature(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded');
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
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

    public function testSuccessfulPayment(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded');
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_success');
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('tr_id_t987', $donation->getTransferId());
        $this->assertEquals('Collected', $donation->getDonationStatus());
        $this->assertEquals('0.37', $donation->getOriginalPspFee());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testOriginalStripeFeeInSEK(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded_sek');
        $donation = $this->getTestDonation();
        $donation->setAmount('6000.00');
        $donation->setCurrencyCode('SEK');

        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_success_sek');
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('tr_id_t988', $donation->getTransferId());
        $this->assertEquals('Collected', $donation->getDonationStatus());
        $this->assertEquals('18.72', $donation->getOriginalPspFee());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSuccessfulFullRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_refunded');
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('Refunded', $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSuccessfulTipRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_tip_refunded');
        $donation = $this->getTestDonation();
        $originalTipAmount = $donation->getTipAmount();
        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('Collected', $donation->getDonationStatus());
        $this->assertEquals('0.00', $donation->getTipAmount());
        // Assert refunded tip amount is equal to the original tip amount
        $this->assertEquals('1.00', $originalTipAmount);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testUnsupportRefundAmount(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_unsupported_partial_refund');
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will also log
        // an error so we can investigate.
        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('Collected', $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(204, $response->getStatusCode());
    }
}
