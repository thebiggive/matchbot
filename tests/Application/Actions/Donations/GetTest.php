<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\Domain\InMemoryDonationRepository;
use MatchBot\Tests\TestCase;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;

class GetTest extends TestCase
{
    use DonationTestDataTrait;
    use PublicJWTAuthTrait;

    public const string DONATION_UUID = '2c6f3408-b405-11ef-a2fe-6b6ac08448a0';
    private InMemoryDonationRepository $donationRepository;

    public function setUp(): void
    {
        parent::setUp();

        $container = $this->getContainer();
        assert($container instanceof Container);
        $this->donationRepository = new InMemoryDonationRepository();

        $container->set(DonationRepository::class, $this->donationRepository);
        $container->set(CampaignRepository::class, $this->createStub(CampaignRepository::class));
        $container->set(DonorAccountRepository::class, $this->createStub(DonorAccountRepository::class));
    }

    public function testMissingId(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $request = $this->createRequest('GET', '/v1/404');
        $this->getAppInstance()->handle($request);
    }

    public function testNoAuth(): void
    {
        $request = $this->createRequest('GET', '/v1/donations/' . self::DONATION_UUID);
        $route = $this->getRouteWithDonationId('get', self::DONATION_UUID);

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $this->getAppInstance()->handle($request->withAttribute('route', $route));
    }

    public function testInvalidAuth(): void
    {
        $jwtWithBadSignature = DonationToken::create(self::DONATION_UUID) . 'x';

        $request = $this->createRequest('GET', '/v1/donations/' . self::DONATION_UUID)
            ->withHeader('x-tbg-auth', $jwtWithBadSignature);
        $route = $this->getRouteWithDonationId('get', self::DONATION_UUID);

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $this->getAppInstance()->handle($request->withAttribute('route', $route));
    }

    public function testAuthForWrongDonation(): void
    {
        $jwtForAnotherDonation = DonationToken::create('87654321-1234-1234-1234-ba0987654321');

        $request = $this->createRequest('GET', '/v1/donations/' . self::DONATION_UUID)
            ->withHeader('x-tbg-auth', $jwtForAnotherDonation);
        $route = $this->getRouteWithDonationId('get', self::DONATION_UUID);

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $this->getAppInstance()->handle($request->withAttribute('route', $route));
    }

    public function testIdNotFound(): void
    {
        $request = $this->createRequest('GET', '/v1/donations/87654321-1234-1234-1234-ba0987654321')
            ->withHeader('x-tbg-auth', DonationToken::create('87654321-1234-1234-1234-ba0987654321'));

        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Donation not found');

        $route = $this->getRouteWithDonationId('get', '87654321-1234-1234-1234-ba0987654321');
        $this->getAppInstance()->handle($request->withAttribute('route', $route));
    }

    public function testSuccess(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */

        $testDonation = $this->getTestDonation(charityComms: true, uuid: self::DONATION_UUID);
        $this->donationRepository->store($testDonation);

        $request = $this->createRequest('GET', '/v1/donations/' . self::DONATION_UUID)
            ->withHeader('x-tbg-auth', DonationToken::create(self::DONATION_UUID));
        $route = $this->getRouteWithDonationId('get', self::DONATION_UUID);

        $response = $app->handle($request->withAttribute('route', $route));
        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertNotEmpty($payloadArray['createdTime']);
        $this->assertEquals('N1 1AA', $payloadArray['billingPostalAddress']);
        $this->assertTrue($payloadArray['giftAid']);
        $this->assertTrue($payloadArray['optInCharityEmail']);
        $this->assertFalse($payloadArray['optInTbgEmail']);
        $this->assertEquals(0, $payloadArray['matchedAmount']);
        $this->assertEquals(1.00, $payloadArray['tipAmount']);
        $this->assertEquals(2.05, $payloadArray['charityFee']); // 1.5% + 20p.
        $this->assertEquals(0, $payloadArray['charityFeeVat']);
    }
}
