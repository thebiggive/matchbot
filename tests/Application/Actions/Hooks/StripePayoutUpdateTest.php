<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\DonationRepository;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

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

    public function testPayoutPaidQueueDispatchError(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $transport = $this->prophesize(InMemoryTransport::class);
        $transport->send(Argument::type(Envelope::class))
            ->willThrow(TransportException::class);
        $container->set(TransportInterface::class, $transport->reveal());

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/po_paid.json');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $webhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testPayoutPaidProcessingQueued(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $transport = new InMemoryTransport();
        $container->set(TransportInterface::class, $transport);

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/StripeWebhook/po_paid.json');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $webhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(1, $transport->getSent());
    }
}
