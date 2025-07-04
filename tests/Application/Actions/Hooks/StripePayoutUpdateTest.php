<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Messenger\Transports;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\SlackChannelChatterFactory;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use MatchBot\Application\Settings;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Notifier\Message\ChatMessage;

class StripePayoutUpdateTest extends StripeTest
{
    const string PAYOUT_TRANSPORT = Transports::TRANSPORT_LOW_PRIORITY;

    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $container->set(SlackChannelChatterFactory::class, $this->createStub(SlackChannelChatterFactory::class));

        // Should 204 no-op if hook mistakenly configured to send this event.
        $body = $this->getStripeHookMock('po_created');
        $webhookSecret = $container->get(Settings::class)->stripe['connectAppWebhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testMissingSignature(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $container->set(SlackChannelChatterFactory::class, $this->createStub(SlackChannelChatterFactory::class));

        $body = $this->getStripeHookMock('ch_succeeded');

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

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPayoutPaidQueueDispatchError(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $container->set(SlackChannelChatterFactory::class, $this->createStub(SlackChannelChatterFactory::class));

        $transport = $this->prophesize(InMemoryTransport::class);
        $transport->send(Argument::type(Envelope::class))
            ->willThrow(TransportException::class);
        $container->set(self::PAYOUT_TRANSPORT, $transport->reveal());

        $body = $this->getStripeHookMock('po_paid');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $webhookSecret = $container->get(Settings::class)->stripe['connectAppWebhookSecret'];
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testPayoutPaidProcessingQueued(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $transport = new InMemoryTransport();
        $container->set(self::PAYOUT_TRANSPORT, $transport);
        $container->set(SlackChannelChatterFactory::class, $this->createStub(SlackChannelChatterFactory::class));

        $body = $this->getStripeHookMock('po_paid');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $webhookSecret = $container->get(Settings::class)->stripe['connectAppWebhookSecret'];
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $transport->getSent());
    }

    public function testPayoutFailedWithNoPaidDonationsFound(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $transport = new InMemoryTransport();
        $container->set(self::PAYOUT_TRANSPORT, $transport);
        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findAllByPayoutId('po_externalId_123')->willReturn([]);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $settings = $container->get(Settings::class);

        $webhookSecret = $settings->stripe['connectAppWebhookSecret'];
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $this->getStripeHookMock('po_failed'))
            ->withHeader(
                'Stripe-Signature',
                $this->generateSignature($time, $this->getStripeHookMock('po_failed'), $webhookSecret),
            );

        $chatterProphecy->send(
            new ChatMessage(
                '[test] payout.failed for ID po_externalId_123, account acct_unitTest543. No donations; if recent, suggests payout.paid never happened'
            )
        )
            ->shouldBeCalledOnce();
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $transport->getSent());
    }

    public function testPayoutFailedAfterSomeDonationsArePaid(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $transport = new InMemoryTransport();
        $container->set(self::PAYOUT_TRANSPORT, $transport);
        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $donation = TestCase::someDonation(collected: true);

        $donationRepoProphecy->findAllByPayoutId('po_externalId_123')->willReturn([$donation]);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $settings = $container->get(Settings::class);

        $webhookSecret = $settings->stripe['connectAppWebhookSecret'];
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe-connect', $this->getStripeHookMock('po_failed'))
            ->withHeader(
                'Stripe-Signature',
                $this->generateSignature($time, $this->getStripeHookMock('po_failed'), $webhookSecret),
            );

        $chatterProphecy->send(
            new ChatMessage(
                '[test] payout.failed for ID po_externalId_123, account acct_unitTest543 (Charity Name). Ran for 1 donations'
            )
        )
            ->shouldBeCalledOnce();
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $transport->getSent());
    }
}
