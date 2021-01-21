<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels\Donation as HttpDonation;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Slim\Exception\HttpNotFoundException;

class DonationUpdateTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

    public function testMissingDonationId(): void
    {
        $app = $this->getAppInstance();

        // Route not matched at all
        $this->expectException(HttpNotFoundException::class);

        $request = $this->createRequest('PUT', '/hooks/donation/');
        $app->handle($request);
    }

    public function testMissingAuth(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('PUT', '/hooks/donation/12345678-1234-1234-1234-1234567890ab');
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(401, ['error' => 'Unauthorized']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testInvalidAuth(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $donation = $this->getHttpDonation(true);
        $body = json_encode($donation);
        $request = $this->createRequest(
            'PUT',
            '/hooks/donation/12345678-1234-1234-1234-1234567890ab',
            $body
        )
            ->withHeader('X-Webhook-Verify-Hash', $this->getValidAuth($body) . 'invalidSuffix');
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(401, ['error' => 'Unauthorized']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testUnrecognisedDonationId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '87654321-1234-1234-1234-ba0987654321'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $body = json_encode($this->getHttpDonation(true));
        $request = $this->createRequest(
            'PUT',
            '/hooks/donation/87654321-1234-1234-1234-ba0987654321',
            $body
        )
            ->withHeader('X-Webhook-Verify-Hash', $this->getValidAuth($body));

        $this->expectException(HttpNotFoundException::class);

        $app->handle($request);
    }

    public function testMissingProperties(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($this->getTestDonation())
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $donation = $this->getHttpDonation(false);
        $body = json_encode($donation);

        $request = $this->createRequest(
            'PUT',
            '/hooks/donation/12345678-1234-1234-1234-1234567890ab',
            $body
        )
            ->withHeader('X-Webhook-Verify-Hash', $this->getValidAuth($body));
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Hook missing required values',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSuccessWithGA(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        // Check tip updates come through in hook responses, while remaining null by default.
        $donation->setTipAmount('4.32');
        $donation->setPsp('enthuse');
        $donation->setCharityFee('enthuse');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $body = json_encode($donation->toHookModel());

        $request = $this->createRequest(
            'PUT',
            '/hooks/donation/12345678-1234-1234-1234-1234567890ab',
            $body
        )
            ->withHeader('X-Webhook-Verify-Hash', $this->getValidAuth($body));

        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals('1 Main St, London N1 1AA', $payloadArray['billingPostalAddress']);
        $this->assertTrue($payloadArray['giftAid']);
        $this->assertTrue($payloadArray['tipGiftAid']);
        $this->assertTrue($payloadArray['optInCharityEmail']);
        $this->assertFalse($payloadArray['optInTbgEmail']);
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(4.32, $payloadArray['tipAmount']);
        $this->assertEquals(3.78, $payloadArray['charityFee']);
    }

    public function testSuccessWithoutGA(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        // Check tip updates come through in hook responses, while remaining null by default.
        $donation->setTipAmount('4.32');
        $donation->setPsp('enthuse');
        $donation->setCharityFee('enthuse');
        $donation->setGiftAid(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $body = json_encode($donation->toHookModel());

        $request = $this->createRequest(
            'PUT',
            '/hooks/donation/12345678-1234-1234-1234-1234567890ab',
            $body
        )
            ->withHeader('X-Webhook-Verify-Hash', $this->getValidAuth($body));

        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals('1 Main St, London N1 1AA', $payloadArray['billingPostalAddress']);
        $this->assertFalse($payloadArray['giftAid']);
        $this->assertTrue($payloadArray['tipGiftAid']);
        $this->assertTrue($payloadArray['optInCharityEmail']);
        $this->assertFalse($payloadArray['optInTbgEmail']);
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(4.32, $payloadArray['tipAmount']);
        $this->assertEquals(2.55, $payloadArray['charityFee']);
    }

    private function getHttpDonation(bool $valid): array
    {
        if ($valid) { // By using getTestDonation() we populate all required fields
            return $this->getTestDonation()->toHookModel();
        }

        // Otherwise, set up a reduced model with some required fields missing so as to be invalid.
        $httpDonation = new HttpDonation();
        $httpDonation->charityId = '123CharityId';
        $httpDonation->donationAmount = 123.45;
        $httpDonation->donationMatched = true;
        $httpDonation->projectId = 'someProject123';

        return (array) $httpDonation;
    }

    private function getValidAuth(string $body): string
    {
        return hash_hmac('sha256', $body, getenv('WEBHOOK_DONATION_SECRET'));
    }
}
