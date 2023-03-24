<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;

class GetTest extends TestCase
{
    use DonationTestDataTrait;
    use PublicJWTAuthTrait;

    public function testMissingId(): void
    {
        $app = $this->getAppInstance();

        // Route not matched at all
        $this->expectException(HttpNotFoundException::class);

        // Use v2 flavour of a v1-valid, now-404 route.
        $request = $this->createRequest('GET', '/v2/donations');
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

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('GET', '/v2/donations/12345678-1234-1234-1234-1234567890ab');
        $route = $this->getRouteWithDonationId('get', '12345678-1234-1234-1234-1234567890ab');

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
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtWithBadSignature = DonationToken::create('12345678-1234-1234-1234-1234567890ab') . 'x';

        $request = $this->createRequest('GET', '/v2/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', $jwtWithBadSignature);
        $route = $this->getRouteWithDonationId('get', '12345678-1234-1234-1234-1234567890ab');

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
            ->findOneBy(['uuid' => '12345678-1234-1234-1234-1234567890ab'])
            ->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $jwtForAnotherDonation = DonationToken::create('87654321-1234-1234-1234-ba0987654321');

        $request = $this->createRequest('GET', '/v2/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', $jwtForAnotherDonation);
        $route = $this->getRouteWithDonationId('get', '12345678-1234-1234-1234-1234567890ab');

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
            ->findOneBy(['uuid' => '87654321-1234-1234-1234-ba0987654321'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('GET', '/v2/donations/87654321-1234-1234-1234-ba0987654321')
            ->withHeader('x-tbg-auth', DonationToken::create('87654321-1234-1234-1234-ba0987654321'));

        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Donation not found');

        $route = $this->getRouteWithDonationId('get', '87654321-1234-1234-1234-ba0987654321');
        $app->handle($request->withAttribute('route', $route));
    }

    public function testSuccess(): void
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

        $request = $this->createRequest('GET', '/v2/donations/12345678-1234-1234-1234-1234567890ab')
            ->withHeader('x-tbg-auth', DonationToken::create('12345678-1234-1234-1234-1234567890ab'));
        $route = $this->getRouteWithDonationId('get', '12345678-1234-1234-1234-1234567890ab');

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertNotEmpty($payloadArray['createdTime']);
        $this->assertEquals('1 Main St, London N1 1AA', $payloadArray['billingPostalAddress']);
        $this->assertTrue($payloadArray['giftAid']);
        $this->assertTrue($payloadArray['optInCharityEmail']);
        $this->assertFalse($payloadArray['optInTbgEmail']);
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(1.00, $payloadArray['tipAmount']);
        $this->assertEquals(2.05, $payloadArray['charityFee']); // 1.5% + 20p.
        $this->assertEquals(0, $payloadArray['charityFeeVat']);
    }
}
