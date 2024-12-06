<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use Ramsey\Uuid\Uuid;
use Slim\Routing\Route;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Stripe\Charge;
use Stripe\ErrorObject;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

class UpdateTest extends TestCase
{
    use DonationTestDataTrait;
    use PublicJWTAuthTrait;

    public const string DONATION_UUID = '3aa347b2-b405-11ef-b2db-e3ab222bcba4';

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(CampaignRepository::class, $this->createStub(CampaignRepository::class));

        $request = $this->createRequest('PUT', '/v1/donations/' . self::DONATION_UUID);
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtWithBadSignature = DonationToken::create(self::DONATION_UUID) . 'x';

        $request = self::createRequest('PUT', '/v1/donations/' . self::DONATION_UUID)
            ->withHeader('x-tbg-auth', $jwtWithBadSignature);
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtForAnotherDonation = DonationToken::create('87654321-1234-1234-1234-ba0987654321');

        $request = self::createRequest('PUT', '/v1/donations/' . self::DONATION_UUID)
            ->withHeader('x-tbg-auth', $jwtForAnotherDonation);
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUUID(Uuid::fromString('87654321-1234-1234-1234-ba0987654321'))
            ->willReturn(null)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);


        $request = $this->createRequest(
            method: 'PUT',
            path: '/v1/donations/87654321-1234-1234-1234-ba0987654321',
            bodyString: json_encode($this->getTestDonation()->toFrontEndApiModel()),
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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($this->getTestDonation()) // Get a new mock object so it's 'Collected'.
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldNotBeCalled();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $donationData = $donation->toFrontEndApiModel();
        unset($donationData['status']); // Simulate an API client omitting the status JSON field

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $stripeCharge = new Charge('testchargeid');
        $stripeCharge->status = 'succeeded';
        $stripeCharge->created = (int)(new \DateTimeImmutable())->format('u');
        $stripeCharge->transfer = 'test_transfer_id';

        $donationResponse->collectFromStripeCharge(
            chargeId: 'testchargeid',
            totalPaidFractional: 100, // irrelevant
            transferId: 'test_transfer_id',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: (int)(new \DateTimeImmutable())->format('U'),
        );

        $donation = $this->getTestDonation();
        $donation->cancel();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationResponse)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $responseDonation = $this->getTestDonation(charityComms: true);
        $responseDonation->cancel();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        // Cancel is a no-op -> no fund release or push to SF
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(DonationStatus::Cancelled->value, $payloadArray['status']);
        $this->assertEquals('N1 1AA', $payloadArray['billingPostalAddress']);
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

        $responseDonation = $this->getTestDonation(charityComms: true);
        // This is the mock repo's response, not the API response. So it's the *prior* state before we cancel the
        // mock donation.
        $responseDonation->setDonationStatus(DonationStatus::Pending);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->cancelPaymentIntent('pi_externalId_123')
            ->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(DonationStatus::Cancelled->value, $payloadArray['status']);
        $this->assertEquals('N1 1AA', $payloadArray['billingPostalAddress']);
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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $stripeErrorMessage = 'You cannot cancel this PaymentIntent because it has a status of ' .
            'succeeded. Only a PaymentIntent with one of the following statuses may be canceled: ' .
            'requires_payment_method, requires_capture, requires_confirmation, requires_action, ' .
            'processing.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->cancelPaymentIntent('pi_externalId_123')
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeErrorMessage = 'You cannot cancel this PaymentIntent because it has a status of ' .
            'canceled. Only a PaymentIntent with one of the following statuses may be canceled: ' .
            'requires_payment_method, requires_capture, requires_confirmation, requires_action, ' .
            'processing.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->cancelPaymentIntent('pi_externalId_123')
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);


        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(DonationStatus::Cancelled->value, $payloadArray['status']);
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
            ->findAndLockOneByUUID(Uuid::fromString('12345678-1234-1234-1234-1234567890ac'))
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->cancelPaymentIntent('pi_stripe_pending_123')
            ->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ac',
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ac'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ac');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(DonationStatus::Cancelled->value, $payloadArray['status']);
        $this->assertFalse($payloadArray['giftAid']);
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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donationInRequest->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        // Remove giftAid after converting to array, as it makes the internal HTTP model invalid.
        $donationData = $donation->toFrontEndApiModel();
        unset($donationData['optInCharityEmail']);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldNotBeCalled();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $bodyArray = $donation->toFrontEndApiModel();
        $bodyArray['homeAddress'] = ['123', 'Main St']; // Invalid array type.

        $body = json_encode($bodyArray);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            $body,
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        // Remove giftAid after converting to array, as it makes the internal HTTP model invalid.
        $donationData = $donation->toFrontEndApiModel();
        unset($donationData['giftAid']);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy);

        $putArray = $donation->toFrontEndApiModel();
        $putArray['tipAmount'] = '25000.01';
        $putJSON = json_encode($putArray);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            $putJSON,
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St',
        );

        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledOnce()
            ->willThrow(UnknownApiErrorException::class);

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St',
        );
        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        // Persist as normal.
        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeErrorMessage = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
            'after a capture has already been made.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);


        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 629;

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->retrievePaymentIntent('pi_externalId_123')
            ->willReturn($mockPI)
            ->shouldBeCalledOnce();
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(3.08, $payloadArray['charityFee']);
    }

    public function testAddDataHitsAlreadyCapturedStripeExceptionWithFeeChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St',
        );

        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        // Internal persist still goes ahead.
        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $stripeErrorMessage = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
            'after a capture has already been made.';
        $stripeApiException = new InvalidRequestException($stripeErrorMessage);


        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 527; // Different from what we'll derive to be right.

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->retrievePaymentIntent('pi_externalId_123')
            ->willReturn($mockPI)
            ->shouldBeCalledOnce();
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledOnce()
            ->willThrow($stripeApiException);

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St',
        );

        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        // Persist as normal.
        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();



        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 629;

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledTimes(2)
            ->will(new PaymentIntentUpdateAttemptTwicePromise(
                true,
                false
            ));

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        // If retry worked, all good from the API client's perspective.
        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(3.08, $payloadArray['charityFee']);
    }

    /**
     * Bails out after failed retry.
     */
    public function testAddDataHitsStripeLockExceptionTwice(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St'
        );

        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        // Internal persist still goes ahead.
        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();



        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 629;

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledTimes(2)
            ->will(new PaymentIntentUpdateAttemptTwicePromise(
                false,
                false,
            ));

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St'
        );
        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        // Persist as normal.
        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();



        $mockPI = new PaymentIntent();
        $mockPI->application_fee_amount = 629;

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->retrievePaymentIntent('pi_externalId_123')
            ->willReturn($mockPI)
            ->shouldBeCalledOnce();
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledTimes(2)
            ->will(new PaymentIntentUpdateAttemptTwicePromise(
                false,
                true,
            ));

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        // If retry for lock worked, and second error is the expected kinds we get
        // intermittently from Stripe + don't really need the update, we should
        // succeed from a donor perspective.
        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertEquals(3.08, $payloadArray['charityFee']);
    }

    public function testAddDataSuccessWithAllValues(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation(currencyCode: 'USD', collected: false);
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '99 Updated St',
            donorBillingPostcode: 'Y1 1YX',
        );
        $donation->setDonorCountryCode('US');
        $donation->setTipAmount('3.21');
        $donation->setTipGiftAid(false);
        $donation->setDonorHomePostcode('X1 1XY');
        $donation->setDonorName(DonorName::of('Saul', 'Williams'));
        $donation->setDonorEmailAddress(EmailAddress::of('saul@example.com'));
        $donation->setTbgComms(true);
        $donation->setCharityComms(false);
        $donation->setChampionComms(false);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', [
            'amount' => 12_666,
            'currency' => 'usd',
            'metadata' => [
                'coreDonationGiftAid' => true,
                'matchedAmount' => '0.0',
                'optInCharityEmail' => false,
                'optInTbgEmail' => true,
                'salesforceId' => 'sfDonation36912345',
                'stripeFeeRechargeGross' => '3.08',
                'stripeFeeRechargeNet' => '3.08',
                'stripeFeeRechargeVat' => '0.00',
                'tbgTipGiftAid' => false,
                'tipAmount' => '3.21',
            ],
            'application_fee_amount' => 629,
        ])
            ->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($donation->toFrontEndApiModel()),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $responseBody = (string) $response->getBody();

        $this->assertJson($responseBody);
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode($responseBody, true);
        \assert(is_array($payload));

        // These two values are unchanged but still returned.
        $this->assertEquals(123.45, $payload['donationAmount']);
        $this->assertEquals(DonationStatus::Pending->value, $payload['status']);

        // Remaining properties should be updated.
        $this->assertEquals('US', $payload['countryCode']);
        $this->assertEquals('USD', $payload['currencyCode']);
        // 1.9% + 20p. cardCountry from Stripe payment method ≠ donor country.
        $this->assertEquals(3.08, $payload['charityFee']);
        $this->assertEquals(0, $payload['charityFeeVat']);
        $this->assertEquals('3.21', $payload['tipAmount']);
        $this->assertTrue($payload['giftAid']);
        $this->assertFalse($payload['tipGiftAid']);
        $this->assertEquals('99 Updated St', $payload['homeAddress']);
        $this->assertEquals('X1 1XY', $payload['homePostcode']);
        $this->assertEquals('Saul', $payload['firstName']);
        $this->assertEquals('Williams', $payload['lastName']);
        $this->assertEquals('saul@example.com', $payload['emailAddress']);
        $this->assertTrue($payload['optInTbgEmail']);
        $this->assertFalse($payload['optInCharityEmail']);
        $this->assertEquals('Y1 1YX', $payload['billingPostalAddress']);
    }

    public function testAddDataSuccessWithCashBalanceAutoconfirm(): void
    {
        [
            'app' => $app,
            'request' => $request,
            'route' => $route,
            'entityManagerProphecy' => $entityManagerProphecy,
        ] =
            $this->setupTestDoublesForConfirmingPaymentFromDonationFunds(
                newPaymentIntentStatus: PaymentIntent::STATUS_SUCCEEDED,
                nextActionRequired: null,
                collectedDonation: false,
            );

        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        // These values are unchanged but still returned. Confirming alone doesn't change the
        // response payload.
        $this->assertEquals(123.45, $payloadArray['donationAmount']);
        $this->assertEquals(DonationStatus::Pending->value, $payloadArray['status']);
    }

    public function testAddDataFailsWithCashBalanceAutoconfirmForDonorWithInsufficentFunds(): void
    {
        [
            'app' => $app,
            'request' => $request,
            'route' => $route,
            'stripeProphecy' => $stripeProphecy,
            'entityManagerProphecy' => $entityManagerProphecy,
        ] =
            $this->setupTestDoublesForConfirmingPaymentFromDonationFunds(
                newPaymentIntentStatus: PaymentIntent::STATUS_PROCESSING,
                nextActionRequired: null,
                collectedDonation: false,
            );
        try {
            $app->handle($request->withAttribute('route', $route));
            $this->fail("attempt to confirm donation with insufficent funds should have thrown");
        } catch (HttpBadRequestException $exception) {
            $this->assertStringContainsString("Status was processing, expected succeeded", $exception->getMessage());
        }

        $stripeProphecy->cancelPaymentIntent('pi_externalId_123')->shouldBeCalled();
        $entityManagerProphecy->flush()->shouldBeCalled(); // flushes cancelled donation to DB.
    }

    public function testAutoconfirmBGTipAttemptRemainsPendingWithCashBalanceInsufficentFunds(): void
    {
        [
            'app' => $app,
            'request' => $request,
            'route' => $route,
            'stripeProphecy' => $stripeProphecy,
            'entityManagerProphecy' => $entityManagerProphecy
        ] = $this->setupTestDoublesForConfirmingPaymentFromDonationFunds(
            newPaymentIntentStatus: PaymentIntent::STATUS_REQUIRES_ACTION,
            nextActionRequired: 'display_bank_transfer_instructions',
        );

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);

        $this->assertEquals(200, $response->getStatusCode());

        /**
         * @psalm-var array{donationAmount:float, status:string} $payloadArray
         */
        $payloadArray = json_decode($payload, true);

        $this->assertEquals(123.45, $payloadArray['donationAmount']);
        // Bank transfer is still required -> status remains pending.
        $this->assertEquals(DonationStatus::Pending->value, $payloadArray['status']);

        // Stripe PI must be left pending ready for the bank transfer.
        $stripeProphecy->cancelPaymentIntent('pi_externalId_123')->shouldNotBeCalled();

        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalled();
        $entityManagerProphecy->commit()->shouldBeCalled();
    }

    public function testAutoconfirmBGTipAttemptAutoCancelsWhenRequiringUnexpectedAction(): void
    {
        [
            'app' => $app,
            'request' => $request,
            'route' => $route,
            'stripeProphecy' => $stripeProphecy,
            'entityManagerProphecy' => $entityManagerProphecy
        ] = $this->setupTestDoublesForConfirmingPaymentFromDonationFunds(
            newPaymentIntentStatus: PaymentIntent::STATUS_REQUIRES_ACTION,
            nextActionRequired: 'any_unexpected_action',
        );

        $entityManagerProphecy->flush()->shouldBeCalled();
        $stripeProphecy->cancelPaymentIntent('pi_externalId_123')->shouldBeCalled();


        $this->expectException(HttpBadRequestException::class);
        $this->expectExceptionMessage('Status was requires_action, expected succeeded');

        $app->handle($request->withAttribute('route', $route));
    }

    public function testAddDataRejectsAutoconfirmWithCardMethod(): void
    {
        // arrange
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        // Get a new mock object so DB has old values. Make it explicit that the payment method type is (the
        // unsupported for auto-confirms) "card".
        $donationInRepo = $this->getTestDonation(pspMethodType: PaymentMethodType::Card);

        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->commit()->shouldNotBeCalled();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent(Argument::cetera())
            ->shouldNotBeCalled();
        $stripeProphecy->confirmPaymentIntent('pi_externalId_123')
            ->shouldNotBeCalled();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $requestPayload = $donation->toFrontEndApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $donation = $this->getTestDonation(pspMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation(pspMethodType: PaymentMethodType::Card);
        // Get a new mock object so DB has old values.
        // Make it explicit that the payment method type is (the unsupported
        // for auto-confirms) "card".

        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->commit()->shouldNotBeCalled();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent(Argument::cetera())
            ->shouldNotBeCalled();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $requestPayload = $donation->toFrontEndApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

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

        $donation = $this->getTestDonation(
            pspMethodType: PaymentMethodType::CustomerBalance,
            tipAmount: '0',
            collected: false,
        );

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        // Get a new mock object so DB has old values. Make it explicit that the payment method type is (the
        // unsupported for auto-confirms) "card".
        $donationInRepo = $this->getTestDonation(
            pspMethodType: PaymentMethodType::CustomerBalance,
            tipAmount: '0',
            collected: false
        );

        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->rollback()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();
        $entityManagerProphecy->commit()->shouldNotBeCalled();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', Argument::type('array'))
            ->shouldBeCalledOnce();
        $stripeProphecy->confirmPaymentIntent('pi_externalId_123')
            ->willThrow(new InvalidRequestException('Not the one we know!'))
            ->shouldBeCalledOnce();

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $requestPayload = $donation->toFrontEndApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        // assert [early]

        // We re-throw this unknown type and leave it to be handled "downstream" in
        // the shutdown handler.
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Not the one we know!');

        // act
        $app->handle($request->withAttribute('route', $route));
    }

    /**
     * @return array{
     *     app: App,
     * request: ServerRequestInterface,
     * route: Route,
     * donationRepoProphecy: ObjectProphecy<DonationRepository>,
     * entityManagerProphecy: ObjectProphecy<RetrySafeEntityManager>,
     * stripeProphecy: ObjectProphecy<Stripe>
     * }
     *
     * @throws \Doctrine\DBAL\Exception\LockWaitTimeoutException
     * @throws \MatchBot\Application\Matching\TerminalLockException
     * @throws \Stripe\Exception\ApiErrorException
     *
     * @psalm-param PaymentIntent::STATUS_* $newPaymentIntentStatus
     */
    public function setupTestDoublesForConfirmingPaymentFromDonationFunds(
        string $newPaymentIntentStatus,
        ?string $nextActionRequired,
        bool $collectedDonation = true,
    ): array {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $nextActionRequired === null
            ? $this->getTestDonation(
                pspMethodType: PaymentMethodType::CustomerBalance,
                tipAmount: '0',
                collected: $collectedDonation
            )
            : $this->getPendingBigGiveGeneralCustomerBalanceDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationInRepo = $this->getTestDonation(
            pspMethodType: PaymentMethodType::CustomerBalance,
            tipAmount: '0',
            collected: false,
        );  // Get a new mock object so DB has old values.

        $donationRepoProphecy
            ->findAndLockOneByUuid(Uuid::fromString(self::DONATION_UUID))
            ->willReturn($donationInRepo)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->updatePaymentIntent('pi_externalId_123', Argument::type('array'))
            ->shouldBeCalledOnce();
        $updatedPaymentIntent = new PaymentIntent('pi_externalId_123');
        $updatedPaymentIntent->status = $newPaymentIntentStatus;

        if ($nextActionRequired !== null) {
            $nextAction = new ErrorObject();
            $nextAction->type = $nextActionRequired;
            $updatedPaymentIntent->next_action = $nextAction;
        }

        $stripeProphecy->confirmPaymentIntent('pi_externalId_123')
            ->shouldBeCalledOnce()
            ->willReturn($updatedPaymentIntent);


        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy);

        $requestPayload = $donation->toFrontEndApiModel();
        $requestPayload['autoConfirmFromCashBalance'] = true;
        $request = $this->createRequest(
            'PUT',
            '/v1/donations/' . self::DONATION_UUID,
            json_encode($requestPayload),
        )
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('put', self::DONATION_UUID);

        return [
            'app' => $app,
            'request' => $request,
            'route' => $route,
            'donationRepoProphecy' => $donationRepoProphecy,
            'entityManagerProphecy' => $entityManagerProphecy,
            'stripeProphecy' => $stripeProphecy,
        ];
    }

    /**
     * @param Container $container
     * @param ObjectProphecy<DonationRepository> $donationRepoProphecy
     * @param ObjectProphecy<RetrySafeEntityManager> $entityManagerProphecy
     * @param ?ObjectProphecy<Stripe> $stripeProphecy
     */
    private function setDoublesInContainer(
        Container $container,
        ObjectProphecy $donationRepoProphecy,
        ObjectProphecy $entityManagerProphecy,
        ?ObjectProphecy $stripeProphecy = null
    ): void {
        $stripeProphecy = $stripeProphecy ?? $this->prophesize(Stripe::class);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());
        $container->set(Stripe::class, $stripeProphecy->reveal());
        $container->set(CampaignRepository::class, $this->prophesize(CampaignRepository::class)->reveal());
        $container->set(DonorAccountRepository::class, $this->prophesize(DonorAccountRepository::class)->reveal());
        $container->set(ClockInterface::class, new MockClock());
    }
}
