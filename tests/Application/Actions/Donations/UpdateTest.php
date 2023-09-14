<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

class UpdateTest extends TestCase
{
    use DonationTestDataTrait;
    use PublicJWTAuthTrait;

    public function testMissingId(): void
    {
        $app = $this->getAppInstance();

        // Route not matched at all
        $this->expectException(HttpNotFoundException::class);

        $request = $this->createRequest('PUT', '/v1/donations/');
        $app->handle($request);
    }

    public function testNoAuth(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('PUT', '/v1/donations/12345678-1234-1234-1234-1234567890ab');
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $app->handle($request->withAttribute('route', $route));
    }

    public function testInvalidAuth(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtWithBadSignature = DonationToken::create('12345678-1234-1234-1234-1234567890ab') . 'x';

        $request = $this->createRequest('PUT', '/v1/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', $jwtWithBadSignature);
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $app->handle($request->withAttribute('route', $route));
    }

    public function testAuthForWrongDonation(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtForAnotherDonation = DonationToken::create('87654321-1234-1234-1234-ba0987654321');

        $request = $this->createRequest('PUT', '/v1/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', $jwtForAnotherDonation);
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $app->handle($request->withAttribute('route', $route));
    }

    public function testIdNotFound(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '87654321-1234-1234-1234-ba0987654321'])
            ->willReturn(null)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest(
            method: 'PUT',
            path: '/v1/donations/87654321-1234-1234-1234-ba0987654321',
            bodyString: json_encode($this->getTestDonation()->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('87654321-1234-1234-1234-ba0987654321'));
        $route = $this->getRouteWithDonationId('put', '87654321-1234-1234-1234-ba0987654321');

        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Donation not found');

        $app->handle($request->withAttribute('route', $route));
    }

    public function testInvalidStatusChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonationStatus(DonationStatus::Failed);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($this->getTestDonation()) // Get a new mock object so it's 'Collected'.
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Status update is only supported for cancellation',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testMissingStatus(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonationStatus(DonationStatus::Pending);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(Argument::cetera())
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $donationData = $donation->toApiModel();
        unset($donationData['status']); // Simulate an API client omitting the status JSON field

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'New status is required',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testCancelRequestAfterDonationFinalised(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationResponse = $this->getTestDonation();
        $donationResponse->setDonationStatus(DonationStatus::Collected);

        $donation = $this->getTestDonation();
        $donation->cancel();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationResponse)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Donation already finalised',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testCancelSuccessWithNoStatusChangesIgnored(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation('999.99');
        $donation->cancel();
        // Check this is ignored and only status patched. N.B. this is currently a bit circular as we simulate both
        // the request and response, but it's (maybe) marginally better than the test not mentioning this behaviour
        // at all.

        $responseDonation = $this->getTestDonation();
        $responseDonation->cancel();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        // Cancel is a no-op -> no fund release or push to SF
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals('Cancelled', $payloadArray['status']);
        $this->assertEquals('1 Main St, London N1 1AA', $payloadArray['billingPostalAddress']);
        $this->assertTrue($payloadArray['giftAid']);
        $this->assertTrue($payloadArray['optInCharityEmail']);
        $this->assertFalse($payloadArray['optInTbgEmail']);
        $this->assertEquals(123.45, $payloadArray['donationAmount']); // Attempt to patch this is ignored
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(1.00, $payloadArray['tipAmount']);
        $this->assertEquals(2.05, $payloadArray['charityFee']); // 1.5% + 20p.
        $this->assertEquals(0, $payloadArray['charityFeeVat']);
    }

    public function testCancelSuccessWithChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation('999.99');
        $donation->cancel();
        // Check this is ignored and only status patched. N.B. this is currently a bit circular as we simulate both
        // the request and response, but it's (maybe) marginally better than the test not mentioning this behaviour
        // at all.

        $responseDonation = $this->getTestDonation();
        // This is the mock repo's response, not the API response. So it's the *prior* state before we cancel the
        // mock donation.
        $responseDonation->setDonationStatus(DonationStatus::Pending);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            // Cancel was a new change and names set -> expect a push to SF.
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->cancel('pi_externalId_123')
            ->shouldBeCalledOnce()
            ->willReturn($this->prophesize(PaymentIntent::class));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals('Cancelled', $payloadArray['status']);
        $this->assertEquals('1 Main St, London N1 1AA', $payloadArray['billingPostalAddress']);
        $this->assertTrue($payloadArray['giftAid']);
        $this->assertTrue($payloadArray['optInCharityEmail']);
        $this->assertFalse($payloadArray['optInTbgEmail']);
        $this->assertEquals(123.45, $payloadArray['donationAmount']); // Attempt to patch this is ignored
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(1.00, $payloadArray['tipAmount']);
        $this->assertEquals(2.05, $payloadArray['charityFee']); // 1.5% + 20p.
        $this->assertEquals(0, $payloadArray['charityFeeVat']);
    }

    /**
     * We *don't* expect this to be possible in normal frontend journeys. It should lead to a 500
     * (as tested here) and a high sev error log so we can investigate.
     */
    public function testCancelSuccessButStripeSaysAlreadySucceeded(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->cancel();

        $responseDonation = $this->getTestDonation();
        // This is the mock repo's response, not the API response. So it's the *prior* state before we cancel the
        // mock donation.
        $responseDonation->setDonationStatus(DonationStatus::Pending);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            // Cancel was a new change and names set -> expect a push to SF.
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeErrorMessage = 'You cannot cancel this PaymentIntent because it has a status of ' .
            'succeeded. Only a PaymentIntent with one of the following statuses may be canceled: ' .
            'requires_payment_method, requires_capture, requires_confirmation, requires_action, ' .
            'processing.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);
        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->cancel('pi_externalId_123')
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * Cover the double-cancel-HTTP-request scenario where we detect that Stripe's already
     * handled a previous cancellation from us.
     */
    public function testCancelSuccessButStripeSaysAlreadyCancelled(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->cancel();

        $responseDonation = $this->getTestDonation();
        // This is the mock repo's response, not the API response. So it's the *prior* state before we cancel the
        // mock donation.
        $responseDonation->setDonationStatus(DonationStatus::Pending);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            // Cancel was a new change and names set -> expect a push to SF.
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeErrorMessage = 'You cannot cancel this PaymentIntent because it has a status of ' .
            'canceled. Only a PaymentIntent with one of the following statuses may be canceled: ' .
            'requires_payment_method, requires_capture, requires_confirmation, requires_action, ' .
            'processing.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);
        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->cancel('pi_externalId_123')
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals('Cancelled', $payloadArray['status']);
    }

    public function testCancelSuccessWithChangeFromPendingAnonymousDonation(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getAnonymousPendingTestDonation();
        $donation->cancel();

        $responseDonation = $this->getAnonymousPendingTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ac'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            // Cancel was a new change BUT donation never had enough
            // data -> DO NOT expect a push to SF.
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->cancel('pi_stripe_pending_123')
            ->shouldBeCalledOnce()
            ->willReturn($this->prophesize(PaymentIntent::class));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ac',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ac'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ac');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals('Cancelled', $payloadArray['status']);
        $this->assertNull($payloadArray['giftAid']);
        $this->assertEquals(124.56, $payloadArray['donationAmount']); // Attempt to patch this is ignored
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(2.00, $payloadArray['tipAmount']);
        $this->assertEquals(2.57, $payloadArray['charityFee']); // 1.9% + 20p.
        $this->assertEquals(0, $payloadArray['charityFeeVat']);
    }

    public function testAddDataAttemptWithDifferentAmount(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationInRequest = $this->getTestDonation('99.99');

        $donationInRepo = $this->getTestDonation(); //  // Get a new mock object so it's £123.45.
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donationInRequest->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Amount updates are not supported',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddDataAttemptWithRequiredBooleanFieldMissing(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // Remove giftAid after converting to array, as it makes the internal HTTP model invalid.
        $donationData = $donation->toApiModel();
        unset($donationData['optInCharityEmail']);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => "Required boolean field 'optInCharityEmail' not set",
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Send a JSON array as "homeAddress".
     */
    public function testAddDataAttemptWithInvalidPropertyType(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(Argument::cetera())
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $bodyArray = $donation->toHookModel();
        $bodyArray['homeAddress'] = ['123', 'Main St']; // Invalid array type.

        $body = json_encode($bodyArray);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            $body,
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Donation Update data deserialise error',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddDataAttemptWithGiftAidMissingDonatingInGBP(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // Remove giftAid after converting to array, as it makes the internal HTTP model invalid.
        $donationData = $donation->toApiModel();
        unset($donationData['giftAid']);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => "Required boolean field 'giftAid' not set",
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddDataAttemptWithTipAboveMaximumAllowed(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // We'll patch the simulated PUT JSON manually because `setTipAmount()` disallows
        // values over the max donation amount.
        $donation = $this->getTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation();  // Get a new mock object so it's £123.45.
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $putArray = $donation->toApiModel();
        $putArray['tipAmount'] = '25000.01';
        $putJSON = json_encode($putArray);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            $putJSON,
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Tip amount must not exceed 25000 GBP',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddDataHitsUnexpectedStripeException(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null)// Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledOnce()
            ->willThrow(UnknownApiErrorException::class);
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals([
            'error' => [
                'type' => 'SERVER_ERROR',
                'description' => 'Could not update Stripe Payment Intent [B]',
            ],
        ], $payloadArray);
    }

    public function testAddDataHitsAlreadyCapturedStripeExceptionWithNoFeeChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldBeCalledOnce();
        $donationRepoProphecy // here
            ->deriveFees(Argument::type(Donation::class), null, null)// Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        // Persist as normal.
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeErrorMessage = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
            'after a capture has already been made.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);
        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);

        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 526;

        $stripePaymentIntentsProphecy->retrieve('pi_externalId_123')
            ->willReturn($mockPI)
            ->shouldBeCalledOnce();
        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(2.05, $payloadArray['charityFee']);
    }

    public function testAddDataHitsAlreadyCapturedStripeExceptionWithFeeChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null)// Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        // Internal persist still goes ahead.
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $stripeErrorMessage = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
            'after a capture has already been made.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);
        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);

        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 527; // Different from what we'll derive to be right.

        $stripePaymentIntentsProphecy->retrieve('pi_externalId_123')
            ->willReturn($mockPI)
            ->shouldBeCalledOnce();
        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals([
            'error' => [
                'type' => 'SERVER_ERROR',
                'description' => 'Could not update Stripe Payment Intent [A]',
            ],
        ], $payloadArray);
    }

    /**
     * Success on retry.
     */
    public function testAddDataHitsStripeLockExceptionOnce(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null) // Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        // Persist as normal.
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);

        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 526;

        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledTimes(2)
            ->will(new PaymentIntentUpdateAttemptTwicePromise(
                true,
                false,
                $this->prophesize(PaymentIntent::class)->reveal(),
            ));

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        // If retry worked, all good from the API client's perspective.
        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(2.05, $payloadArray['charityFee']);
    }

    /**
     * Bails out after failed retry.
     */
    public function testAddDataHitsStripeLockExceptionTwice(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null) // Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        // Internal persist still goes ahead.
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);

        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 526;

        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledTimes(2)
            ->will(new PaymentIntentUpdateAttemptTwicePromise(
                false,
                false,
                $this->prophesize(PaymentIntent::class)->reveal(),
            ));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals([
            'error' => [
                'type' => 'SERVER_ERROR',
                'description' => 'Could not update Stripe Payment Intent [C]',
            ],
        ], $payloadArray);
    }

    /**
     * Should only info log + succeed from a donor perspective on second attempt.
     */
    public function testAddDataHitsStripeLockExceptionThenAlreadyCapturedWithNoFeeChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null)// Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        // Persist as normal.
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);

        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 526;

        $stripePaymentIntentsProphecy->retrieve('pi_externalId_123')
            ->willReturn($mockPI)
            ->shouldBeCalledOnce();
        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledTimes(2)
            ->will(new PaymentIntentUpdateAttemptTwicePromise(
                false,
                true,
                $this->prophesize(PaymentIntent::class)->reveal(),
            ));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        // If retry for lock worked, and second error is the expected kinds we get
        // intermittently from Stripe + don't really need the update, we should
        // succeed from a donor perspective.
        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(2.05, $payloadArray['charityFee']);
    }

    public function testAddDataSuccessWithAllValues(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
        $donation->setCurrencyCode('USD');
        $donation->setTipAmount('3.21');
        $donation->setGiftAid(true);
        $donation->setTipGiftAid(false);
        $donation->setDonorHomeAddressLine1('99 Updated St');
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorFirstName('Saul');
        $donation->setDonorLastName('Williams');
        $donation->setDonorEmailAddress('saul@example.com');
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldBeCalledOnce(); // Updates pushed to Salesforce
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null) // Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->update('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation369',
                'stripeFeeRechargeGross' => '2.05',
                'stripeFeeRechargeNet' => '2.05',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 526,
        ])
            ->shouldBeCalledOnce();
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        // These two values are unchanged but still returned.
        $this->assertEquals(123.45, $payloadArray['donationAmount']);
        $this->assertEquals('Collected', $payloadArray['status']);

        // Remaining properties should be updated.
        $this->assertEquals('US', $payloadArray['countryCode']);
        $this->assertEquals('USD', $payloadArray['currencyCode']);
        // 1.9% + 20p. cardCountry from Stripe payment method ≠ donor country.
        $this->assertEquals(2.05, $payloadArray['charityFee']);
        $this->assertEquals(0, $payloadArray['charityFeeVat']);
        $this->assertEquals('3.21', $payloadArray['tipAmount']);
        $this->assertTrue($payloadArray['giftAid']);
        $this->assertFalse($payloadArray['tipGiftAid']);
        $this->assertEquals('99 Updated St', $payloadArray['homeAddress']);
        $this->assertEquals('X1 1XY', $payloadArray['homePostcode']);
        $this->assertEquals('Saul', $payloadArray['firstName']);
        $this->assertEquals('Williams', $payloadArray['lastName']);
        $this->assertEquals('saul@example.com', $payloadArray['emailAddress']);
        $this->assertTrue($payloadArray['optInTbgEmail']);
        $this->assertFalse($payloadArray['optInCharityEmail']);
        $this->assertEquals('Y1 1YX', $payloadArray['billingPostalAddress']);
    }

    public function testAddDataSuccessWithCashBalanceAutoconfirm(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');  // Get a new mock object so DB has old values.

        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldBeCalledOnce(); // Updates pushed to Salesforce
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null) // Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->update('pi_externalId_123', Argument::type('array'))
            ->shouldBeCalledOnce();
        $stripePaymentIntentsProphecy->confirm('pi_externalId_123')
            ->shouldBeCalledOnce();
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $requestPayload = $donation->toApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        // These values are unchanged but still returned. Confirming alone doesn't change the
        // response payload.
        $this->assertEquals(123.45, $payloadArray['donationAmount']);
        $this->assertEquals('Collected', $payloadArray['status']);
    }

    public function testAddDataRejectsAutoconfirmWithCardMethod(): void
    {
        // arrange
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation(paymentMethodType: PaymentMethodType::Card);  // Get a new mock object so DB has old values.
        // Make it explicit that the payment method type is (the unsupported
        // for auto-confirms) "card".

        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled(); // Updates pushed to Salesforce

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->commit()->shouldNotBeCalled();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->update(Argument::cetera())
            ->shouldNotBeCalled();
        $stripePaymentIntentsProphecy->confirm('pi_externalId_123')
            ->shouldNotBeCalled();
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $requestPayload = $donation->toApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        // act
        $response = $app->handle($request->withAttribute('route', $route));

        // assert
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(400, $response->getStatusCode());

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Processing incomplete. Please refresh and check your donation funds balance',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
    }

    public function testCannotAutoConfirmCardPayment(): void
    {
        // arrange
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation(paymentMethodType: PaymentMethodType::Card);
        // Get a new mock object so DB has old values.
        // Make it explicit that the payment method type is (the unsupported
        // for auto-confirms) "card".

        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled(); // Updates pushed to Salesforce

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->commit()->shouldNotBeCalled();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->update(Argument::cetera())
            ->shouldNotBeCalled();
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $requestPayload = $donation->toApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        // act
        $response = $app->handle($request->withAttribute('route', $route));

        // assert
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(400, $response->getStatusCode());

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Processing incomplete. Please refresh and check your donation funds balance',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
    }

    public function testAddDataAutoconfirmHitsUnknownInvalidRequestException(): void
    {
        // arrange
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');  // Get a new mock object so DB has old values.
        // Make it explicit that the payment method type is (the unsupported
        // for auto-confirms) "card".

        $donationRepoProphecy
            ->findAndLockOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->deriveFees(Argument::type(Donation::class), null, null)// Actual fee calculation is tested elsewhere.
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled(); // Updates pushed to Salesforce

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->commit()->shouldNotBeCalled();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->update('pi_externalId_123', Argument::type('array'))
            ->shouldBeCalledOnce();
        $stripePaymentIntentsProphecy->confirm('pi_externalId_123')
            ->willThrow(new InvalidRequestException('Not the one we know!'))
            ->shouldBeCalledOnce();
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $requestPayload = $donation->toApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        // assert [early]

        // We re-throw this unknown type and leave it to be handled "downstream" in
        // the shutdown handler.
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Not the one we know!');

        // act
        $app->handle($request->withAttribute('route', $route));
    }
}
