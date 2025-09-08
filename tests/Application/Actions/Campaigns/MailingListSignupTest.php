<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Campaigns;

use DI\Container;
use GuzzleHttp\Client;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\CaptchaMiddleware;
use MatchBot\Application\Auth\FriendlyCaptchaVerifier;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\MailingList;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

class MailingListSignupTest extends TestCase
{
    /**
     * Create a request with proper headers for JSON content
     */
    private function createJsonRequest(string $method, string $path, array $data): \Psr\Http\Message\ServerRequestInterface
    {
        $jsonBody = (string) json_encode($data);
        $request = $this->createRequest(
            $method,
            $path,
            $jsonBody,
            [
                'Content-Type' => 'application/json',
                'HTTP_CONTENT_TYPE' => 'application/json'
            ]
        );

        // Set the parsed body directly to simulate proper JSON parsing
        return $request->withParsedBody($data);
    }

    /**
     * Set up the mocked CaptchaMiddleware with bypass enabled
     */
    private function setupCaptchaMiddleware(Container $container): void
    {
        // Mock the dependencies for CaptchaMiddleware
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $clientProphecy = $this->prophesize(Client::class);

        // Create a FriendlyCaptchaVerifier with mocked dependencies
        $friendlyCaptchaVerifier = new FriendlyCaptchaVerifier(
            $clientProphecy->reveal(),
            'test-secret',
            'test-site-key',
            $loggerProphecy->reveal()
        );

        // Create a CaptchaMiddleware with bypass enabled
        $captchaMiddleware = new CaptchaMiddleware(
            $loggerProphecy->reveal(),
            $friendlyCaptchaVerifier,
            true // bypass captcha for tests
        );

        // Register the middleware in the container
        $container->set(CaptchaMiddleware::class, $captchaMiddleware);
    }

    public function testSuccessfulDonorSignup(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $this->assertTrue($container instanceof Container);

        // Set up the CaptchaMiddleware with bypass enabled
        $this->setupCaptchaMiddleware($container);

        // Mock the MailingList client
        $mailingListClientProphecy = $this->prophesize(MailingList::class);
        $mailingListClientProphecy
            ->signup(
                'donor',
                'Test',
                'User',
                'test@example.com',
                null,
                'Test Organization'
            )
            ->willReturn(true);

        $container->set(MailingList::class, $mailingListClientProphecy->reveal());

        // Create a request with JSON body
        $request = $this->createJsonRequest(
            'POST',
            '/v1/mailing-list-signup',
            [
                'mailinglist' => 'donor',
                'firstName' => 'Test',
                'lastName' => 'User',
                'emailAddress' => 'test@example.com',
                'organisationName' => 'Test Organization'
            ]
        );

        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $this->assertEquals(200, $response->getStatusCode());

        /** @var array{success: bool, message: string} $responseData */
        $responseData = json_decode($payload, true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals('Successfully signed up to mailing list', $responseData['message']);
    }

    public function testSuccessfulCharitySignup(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $this->assertTrue($container instanceof Container);

        // Set up the CaptchaMiddleware with bypass enabled
        $this->setupCaptchaMiddleware($container);

        // Mock the MailingList client
        $mailingListClientProphecy = $this->prophesize(MailingList::class);
        $mailingListClientProphecy
            ->signup(
                'charity',
                'Test',
                'User',
                'test@example.com',
                'CEO',
                'Test Charity'
            )
            ->willReturn(true);

        $container->set(MailingList::class, $mailingListClientProphecy->reveal());

        // Create a request with JSON body
        $request = $this->createJsonRequest(
            'POST',
            '/v1/mailing-list-signup',
            [
                'mailinglist' => 'charity',
                'firstName' => 'Test',
                'lastName' => 'User',
                'emailAddress' => 'test@example.com',
                'jobTitle' => 'CEO',
                'organisationName' => 'Test Charity'
            ]
        );

        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $this->assertEquals(200, $response->getStatusCode());

        /** @var array{success: bool, message: string} $responseData */
        $responseData = json_decode($payload, true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals('Successfully signed up to mailing list', $responseData['message']);
    }

    public function testMissingRequiredField(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $this->assertTrue($container instanceof Container);

        // Set up the CaptchaMiddleware with bypass enabled
        $this->setupCaptchaMiddleware($container);

        // Create a request with missing required field (jobTitle for charity)
        $request = $this->createJsonRequest(
            'POST',
            '/v1/mailing-list-signup',
            [
                'mailinglist' => 'charity',
                'firstName' => 'Test',
                'lastName' => 'User',
                'emailAddress' => 'test@example.com'
            ]
        );

        // We expect an exception to be thrown for missing required field
        $this->expectException(\Slim\Exception\HttpBadRequestException::class);
        $this->expectExceptionMessage('Job title is required for charity mailing list');

        $app->handle($request);
    }

    public function testInvalidMailingListType(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $this->assertTrue($container instanceof Container);

        // Set up the CaptchaMiddleware with bypass enabled
        $this->setupCaptchaMiddleware($container);

        // Create a request with invalid mailing list type
        $request = $this->createJsonRequest(
            'POST',
            '/v1/mailing-list-signup',
            [
                'mailinglist' => 'invalid',
                'firstName' => 'Test',
                'lastName' => 'User',
                'emailAddress' => 'test@example.com',
                'jobTitle' => 'CEO',
                'organisationName' => 'Test Charity'
            ]
        );

        // We expect an exception to be thrown for invalid mailing list type
        $this->expectException(\Slim\Exception\HttpBadRequestException::class);
        $this->expectExceptionMessage('Mailing list must be either "donor" or "charity"');

        $app->handle($request);
    }

    public function testClientError(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $this->assertTrue($container instanceof Container);

        // Set up the CaptchaMiddleware with bypass enabled
        $this->setupCaptchaMiddleware($container);

        // Mock the MailingList client to throw an exception
        $mailingListClientProphecy = $this->prophesize(MailingList::class);
        $mailingListClientProphecy
            ->signup(
                'donor',
                'Test',
                'User',
                'test@example.com',
                null,
                'Test Organization'
            )
            ->willThrow(new BadRequestException('Test error'));

        // Mock the logger to verify error is logged
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error(Argument::containingString('Mailing list signup failed'))->shouldBeCalled();

        $container->set(MailingList::class, $mailingListClientProphecy->reveal());
        $container->set(LoggerInterface::class, $loggerProphecy->reveal());

        // Create a request with JSON body
        $request = $this->createJsonRequest(
            'POST',
            '/v1/mailing-list-signup',
            [
                'mailinglist' => 'donor',
                'firstName' => 'Test',
                'lastName' => 'User',
                'emailAddress' => 'test@example.com',
                'organisationName' => 'Test Organization'
            ]
        );

        $response = $app->handle($request);

        /** @var array{success: bool, message: string} $payload */
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertEquals('Failed to sign up to mailing list', $payload['message']);
    }

    public function testServerError(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $this->assertTrue($container instanceof Container);

        // Set up the CaptchaMiddleware with bypass enabled
        $this->setupCaptchaMiddleware($container);

        // Mock the MailingList client to return false
        $mailingListClientProphecy = $this->prophesize(MailingList::class);
        $mailingListClientProphecy
            ->signup(
                'donor',
                'Test',
                'User',
                'test@example.com',
                null,
                'Test Organization'
            )
            ->willReturn(false);

        $container->set(MailingList::class, $mailingListClientProphecy->reveal());

        // Create a request with JSON body
        $request = $this->createJsonRequest(
            'POST',
            '/v1/mailing-list-signup',
            [
                'mailinglist' => 'donor',
                'firstName' => 'Test',
                'lastName' => 'User',
                'emailAddress' => 'test@example.com',
                'organisationName' => 'Test Organization'
            ]
        );

        $response = $app->handle($request);

        /** @var array{success: bool, message: string} $payload */
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertEquals('Failed to sign up to mailing list', $payload['message']);
    }
}
