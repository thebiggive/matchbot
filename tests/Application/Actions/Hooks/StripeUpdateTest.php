<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\Actions\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Stripe\Service\BalanceTransactionService;
use Stripe\StripeClient;

class StripeUpdateTest extends TestCase
{
    use DonationTestDataTrait;

    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // Payment Intent events, including cancellations, return a 204 No Content no-op for now.
        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/pi_canceled.json');
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
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

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/ch_invalid_id.json');
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
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

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/ch_succeeded.json');

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

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/ch_succeeded.json');
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
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

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('Collected', $donation->getDonationStatus());
        $this->assertEquals(200, $response->getStatusCode());
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
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all([
            'limit' => 100,
            'payout' => 'po_externalId_123',
            'type' => 'charge'
        ])
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_invalidId_123'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
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
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
        $time = (string) time();

        $donation->setDonationStatus('Failed');

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all([
            'limit' => 100,
            'payout' => 'po_externalId_123',
            'type' => 'charge'
        ])
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
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
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->all([
            'limit' => 100,
            'payout' => 'po_externalId_123',
            'type' => 'charge'
        ])
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));

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

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('Paid', $donation->getDonationStatus());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSuccessfulRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/ch_refunded.json');
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
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
        $this->assertEquals(200, $response->getStatusCode());
    }

    private function generateSignature(string $time, string $body, string $webhookSecret): string
    {
        return 't=' . $time . ',' . 'v1=' . $this->getValidAuth($this->getSignedPayload($time, $body), $webhookSecret);
    }

    private function getSignedPayload(string $time, string $body): string
    {
        $time = (string) time();
        return $time . '.' . $body;
    }

    private function getValidAuth(string $signedPayload, string $webhookSecret): string
    {
        return hash_hmac('sha256', $signedPayload, $webhookSecret);
    }
}
