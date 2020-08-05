<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\Token;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\Actions\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Slim\Exception\HttpNotFoundException;

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
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
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

        $response = $app->handle($request->withAttribute('route', $route));
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
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtWithBadSignature = Token::create('12345678-1234-1234-1234-1234567890ab') . 'x';

        $request = $this->createRequest('PUT', '/v1/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', $jwtWithBadSignature);
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(401, ['error' => 'Unauthorized']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testAuthForWrongDonation(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtForAnotherDonation = Token::create('87654321-1234-1234-1234-ba0987654321');

        $request = $this->createRequest('PUT', '/v1/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', $jwtForAnotherDonation);
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(401, ['error' => 'Unauthorized']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testIdNotFound(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '87654321-1234-1234-1234-ba0987654321'])
            ->willReturn(null)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('PUT', '/v1/donations/87654321-1234-1234-1234-ba0987654321')
            ->withHeader('x-tbg-auth', Token::create('87654321-1234-1234-1234-ba0987654321'));
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
        $donation->setDonationStatus('Failed');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($this->getTestDonation()) // Get a new mock object so it's 'Collected'.
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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
        $donation->setDonationStatus('Pending');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $donationData = $donation->toApiModel();
        unset($donationData['status']); // Simulate an API client omitting the status JSON field

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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
        $donationResponse->setDonationStatus('Collected');

        $donation = $this->getTestDonation();
        $donation->setDonationStatus('Cancelled');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donationResponse)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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

        $donation = $this->getTestDonation();
        $donation->setDonationStatus('Cancelled');
        // Check this is ignored and only status patched. N.B. this is currently a bit circular as we simulate both
        // the request and response, but it's (maybe) marginally better than the test not mentioning this behaviour
        // at all.
        $donation->setAmount('999.99');

        $responseDonation = $this->getTestDonation();
        $responseDonation->setDonationStatus('Cancelled');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        // Cancel is a no-op -> no fund release or push to SF
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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
        $this->assertEquals(0, $payloadArray['tipAmount']);
    }

    public function testCancelSuccessWithChange(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonationStatus('Cancelled');
        // Check this is ignored and only status patched. N.B. this is currently a bit circular as we simulate both
        // the request and response, but it's (maybe) marginally better than the test not mentioning this behaviour
        // at all.
        $donation->setAmount('999.99');

        $responseDonation = $this->getTestDonation();
        // This is the mock repo's response, not the API response. So it's the *prior* state before we cancel the
        // mock donation.
        $responseDonation->setDonationStatus('Pending');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($responseDonation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce(); // Cancel was a new change -> expect a push to SF

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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
        $this->assertEquals(0, $payloadArray['tipAmount']);
    }

    public function testAddDataAttemptWithDifferentAmount(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setAmount('99.99');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($this->getTestDonation()) // Get a new mock object so it's £123.45.
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        // Remove giftAid after converting to array, as it makes the internal HTTP model invalid.
        $donationData = $donation->toApiModel();
        unset($donationData['giftAid']);

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donationData),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('put', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Required boolean fields not set',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddDataSuccess(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $donation = $this->getTestDonation();
        $donation->setDonorCountryCode('US');
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
        $donation->setDonorBillingAddress('Y1 1YX');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->willReturn($this->getTestDonation()) // Get a new mock object so DB has old values.
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->shouldBeCalledOnce(); // Updates pushed to Salesforce

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest(
            'PUT',
            '/v1/donations/12345678-1234-1234-1234-1234567890ab',
            json_encode($donation->toApiModel()),
        )
            ->withHeader('x-tbg-auth', Token::create('12345678-1234-1234-1234-1234567890ab'));
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
}
