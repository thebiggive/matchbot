<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels\Donation as HttpDonation;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\StripeWebhook;
use MatchBot\Tests\Application\Actions\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Slim\Exception\HttpNotFoundException;

class StripeUpdateTest extends TestCase
{
    use DonationTestDataTrait;

    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = file_get_contents(dirname(__DIR__, 3) . '/TestData/canceled.json');
        $donation = $this->getTestDonation();
        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $stripeRepoProphecy = $this->prophesize(StripeWebhook::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(StripeWebhook::class, $stripeRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));
        
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Unsupported Action',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }


    private function generateSignature(string $time, string $body, string $webhookSecret) {
        return 't=' . $time . ',' . 'v1=' . $this->getValidAuth($this->getSignedPayload($time, $body), $webhookSecret);
    }

    private function getSignedPayload(string $time, string $body) {
        $time = (string) time();
        return $time . '.' . $body;
    }

    private function getValidAuth(string $signedPayload, string $webhookSecret): string
    {
        return hash_hmac('sha256', $signedPayload, $webhookSecret);
    }
}
