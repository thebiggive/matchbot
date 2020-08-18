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

        $stripeRepoProphecy = $this->prophesize(StripeWebhook::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $container->set(StripeWebhook::class, $stripeRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $webhookSecret = $container->get('settings')['stripe']['webhookSecret'];
        $stripeSignature = 't=' . time() . 'v1=' . $this->getValidAuth($body, $webhookSecret) . 'v0=' . $this->getValidAuth($body, $webhookSecret);

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('stripe-signature', $stripeSignature);
        
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => 'Unsupported Action']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    private function getValidAuth(string $body, string $webhookSecret): string
    {
        return hash_hmac('sha256', $body, $webhookSecret);
    }
}
