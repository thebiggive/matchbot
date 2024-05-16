<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Los\RateLimit\Exception\MissingRequirement;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use Prophecy\Argument;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Slim\App;
use Slim\Exception\HttpUnauthorizedException;
use Stripe\Exception\PermissionException;
use Stripe\PaymentIntent;
use Symfony\Component\Notifier\Message\ChatMessage;
use UnexpectedValueException;

class CreateTest extends TestCase
{
    private static array $somePaymentIntentArgs;
    /**
     * @var PaymentIntent Mock result, most properites we don't use omitted.
     * @link https://stripe.com/docs/api/payment_intents/object
     */
    private static PaymentIntent $somePaymentIntentResult;

    public function setUp(): void
    {
        parent::setUp();

        static::$somePaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => 'cus_aaaaaaaaaaaa11',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => '12345678-1234-1234-1234-1234567890ab',
                'environment' => getenv('APP_ENV'),
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '0.00',
                'stripeFeeRechargeGross' => '0.43', // Includes Gift Aid processing fee
                'stripeFeeRechargeNet' => '0.43',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
            'setup_future_usage' => 'on_session',
            'statement_descriptor' => 'Big Give Create test c',
            'application_fee_amount' => 154,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        static::$somePaymentIntentResult = new PaymentIntent([
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ]);

        $app = $this->getAppInstance();

        /** @var Container $container */
        $container = $app->getContainer();

        $campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $container->set(CampaignRepository::class, $campaignRepositoryProphecy->reveal());
    }

    /**
     * While we don't test it separately, we now expect invalid `paymentMethodType` to be caught by the
     * same condition, as the property is now an enum.
     */
    public function testDeserialiseError(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = '{"not-good-json';

        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Donation Create data deserialise error',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testCampaignClosed(): void
    {
        $donation = $this->getTestDonation(false, false);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Campaign 123CampaignId12345 is not open',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testStripeWithMissingStripeAccountID(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->getCampaign()->getCharity()->setStripeAccountId(null);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donationToReturn);
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();
        $donationRepoProphecy->push(Argument::type(Donation::class), Argument::type('bool'))->shouldNotBeCalled();

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->updateFromSf(Argument::type(Campaign::class))
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $container->set(CampaignRepository::class, $campaignRepoProphecy->reveal());

        $entityManagerProphecy->persistWithoutRetries(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::any())->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());
        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertEquals('SERVER_ERROR', $payloadArray['error']['type']);
        $this->assertEquals('Could not make Stripe Payment Intent (A)', $payloadArray['error']['description']);
    }

    public function testCurrencyMismatch(): void
    {
        $donation = $this->getTestDonation(true, false, true, 'CAD');

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willThrow(new UnexpectedValueException('Currency CAD is invalid for campaign'));
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->persistWithoutRetries(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Currency CAD is invalid for campaign',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * 'test' env should expect and trust
     */
    public function testNoXForwardedForHeader(): void
    {
        $this->expectException(MissingRequirement::class);
        $this->expectExceptionMessage('Could not detect the client IP');

        $donation = $this->getTestDonation(true, false, true);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->shouldNotBeCalled();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $entityManagerProphecy->persistWithoutRetries(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest(
            'POST',
            TestData\Identity::getTestPersonNewDonationEndpoint(),
            $data,
            ['HTTP_ACCEPT' => 'application/json'], // Un-set forwarded IP header.
        );
        $app->handle($this->addDummyPersonAuth($request)); // Rate limit middleware should bail out.
    }

    public function testSuccessWithStripeAccountIDMissingInitiallyButFoundOnRefetch(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.
        $donation->getCampaign()->getCharity()->setStripeAccountId(null);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: true,
            donation: $donationToReturn,
            skipEmExpectations: true,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        // Cloning & use of new objects is necessary here, so we don't set
        // the Stripe value on the copy of the object which is meant to be
        // missing it for the test to follow that logic branch.
        $charityWhichNowHasStripeAccountID = clone $donation->getCampaign()->getCharity();
        $charityWhichNowHasStripeAccountID
            ->setStripeAccountId('unitTest_newStripeAccount_456');

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->updateFromSf(Argument::type(Campaign::class))
            ->will(/**
             * @param array{0: Campaign} $args
             */                fn (array $args) => $args[0]->setCharity($charityWhichNowHasStripeAccountID)
            );

        // Need to override stock EM to get campaign repo behaviour
        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        $container->set(CampaignRepository::class, $campaignRepoProphecy->reveal());
        $entityManagerProphecy->persistWithoutRetries(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => 'cus_aaaaaaaaaaaa11',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => '12345678-1234-1234-1234-1234567890ab',
                'environment' => getenv('APP_ENV'),
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.38',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
            'setup_future_usage' => 'on_session',
            'statement_descriptor' => 'Big Give Create test c',
            'application_fee_amount' => 149,
            'on_behalf_of' => 'unitTest_newStripeAccount_456',
            'transfer_data' => [
                'destination' => 'unitTest_newStripeAccount_456',
            ],
        ];
        // Most properites we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = new PaymentIntent([
            'id' => 'pi_dummyIntent456_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_456',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ]);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());
        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(0.38, $payloadArray['donation']['charityFee']);
        $this->assertEquals(0, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
        $this->assertEquals(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent456_id', $payloadArray['donation']['transactionId']);
    }

    public function testSuccessWithMatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.

        $fundingWithdrawalForMatch = new FundingWithdrawal(self::someCampaignFunding());
        $fundingWithdrawalForMatch->setAmount('8.00'); // Partial match
        $fundingWithdrawalForMatch->setDonation($donation);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal($fundingWithdrawalForMatch);

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: true,
            donation: $donationToReturn,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $expectedPaymentIntentArgs = [
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => '12345678-1234-1234-1234-1234567890ab',
                'environment' => getenv('APP_ENV'),
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.38',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
            'statement_descriptor' => 'Big Give Create test c',
            'application_fee_amount' => 149,
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
            'customer' => 'cus_aaaaaaaaaaaa11',
            'setup_future_usage' => 'on_session'
        ];

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();

        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(0.38, $payloadArray['donation']['charityFee']); // 1.5% + 20p.
        $this->assertEquals(0, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
        $this->assertEquals(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
    }

    public function testSuccessWithMatchedCampaignAndPspCustomerId(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.
        $donation->setPspCustomerId('cus_aaaaaaaaaaaa11');

        $fundingWithdrawalForMatch = new FundingWithdrawal(self::someCampaignFunding());
        $fundingWithdrawalForMatch->setAmount('8.00'); // Partial match
        $fundingWithdrawalForMatch->setDonation($donation);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: true,
            donation: $donationToReturn,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => 'cus_aaaaaaaaaaaa11',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => '12345678-1234-1234-1234-1234567890ab',
                'environment' => getenv('APP_ENV'),
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.38',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
            'setup_future_usage' => 'on_session',
            'statement_descriptor' => 'Big Give Create test c',
            'application_fee_amount' => 149,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();

        $container->set(Stripe::class, $stripeProphecy->reveal());

        $request = $this->createRequest(
            'POST',
            TestData\Identity::getTestPersonNewDonationEndpoint(),
            $this->encode($donation),
        );
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(0.38, $payloadArray['donation']['charityFee']); // 1.5% + 20p.
        $this->assertEquals(0, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
        $this->assertEquals(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
    }

    public function testMatchedCampaignButWrongPersonInRoute(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        // Default test Customer ID is cus_aaaaaaaaaaaa11.
        $donation = $this->getTestDonation(true, true);
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: false,
            donationPushed: false,
            donationMatched: false,
            donation: $donationToReturn,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->shouldNotBeCalled();

        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = json_encode($donation->toApiModel(), JSON_THROW_ON_ERROR);
        // Don't match default test customer ID from body, in this path.
        $request = $this->createRequest('POST', '/v1/people/99999999-1234-1234-1234-1234567890zz/donations', $data);
        $app->handle($this->addDummyPersonAuth($request)); // Throws HttpUnauthorizedException.
    }

    public function testMatchedCampaignButWrongCustomerIdInBody(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.
        $donation->setPspCustomerId('cus_zzaaaaaaaaaa99');

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: false,
            donationPushed: false,
            donationMatched: false,
            donation: $donationToReturn,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->shouldNotBeCalled();

        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = json_encode($donation->toApiModel(), JSON_THROW_ON_ERROR);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);

        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Route customer ID cus_aaaaaaaaaaaa11 did not match cus_zzaaaaaaaaaa99 in donation body',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSuccessWithMatchedCampaignAndInitialCampaignDuplicateError(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        // Use a custom Prophecy Promise to vary the simulated behaviour.
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->will(new CreateDupeCampaignThrowThenSucceedPromise($donationToReturn))
            ->shouldBeCalledTimes(2); // One exception, one success

        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);
        // These are called once after initial ID setup and once after Stripe fields added.
        $entityManagerProphecy->persistWithoutRetries(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => 'cus_aaaaaaaaaaaa11',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => '12345678-1234-1234-1234-1234567890ab',
                'environment' => getenv('APP_ENV'),
                'feeCoverAmount' => '0.00',
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.38',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
            'setup_future_usage' => 'on_session',
            'statement_descriptor' => 'Big Give Create test c',
            'application_fee_amount' => 149,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());
        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(0.38, $payloadArray['donation']['charityFee']);
        $this->assertEquals(0, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
        $this->assertEquals(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
    }

    public function testSuccessWithUnmatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, false);

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: false,
            donation: $donation,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(self::$somePaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();

        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(0.43, $payloadArray['donation']['charityFee']); // 1.9% + 20p.
        $this->assertEquals(0, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
    }

    /**
     * Use unmatched campaign in previous test but also omit all donor-supplied
     * detail except donation and tip amount, to test new 2-step Create setup.
     */
    public function testSuccessWithMinimalData(): void
    {
        $donation = $this->getTestDonation(true, false, true);
        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: false,
            donation: $donation,
        );
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(self::$somePaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();

        $container->set(Stripe::class, $stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertNotEmpty($payloadArray['donation']['createdTime']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertNull($payloadArray['donation']['optInCharityEmail']);
        $this->assertNull($payloadArray['donation']['optInChampionEmail']);
        $this->assertNull($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals(0.43, $payloadArray['donation']['charityFee']); // 1.9% + 20p.
        $this->assertEquals(0, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
    }

    /**
     * Get app with standard EM & repo set. Callers in this class typically set a prophesised Stripe client
     * on the container.
     *
     * @param bool $skipEmExpectations  Whether to bypass monitoring calls on the entity manager,
     *                                  e.g. because the test will replace it with a more specific one.
     */
    private function getAppWithCommonPersistenceDeps(
        bool $donationPersisted,
        bool $donationPushed,
        bool $donationMatched,
        Donation $donation,
        bool $skipEmExpectations = false,
    ): App {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);

        if ($donationPushed) {
            $donationRepoProphecy->push($donation, true)->shouldBeCalledOnce();
        } else {
            $donationRepoProphecy->push($donation, true)->shouldNotBeCalled();
        }

        if ($donationMatched) {
            $donationRepoProphecy->allocateMatchFunds($donation)->shouldBeCalledOnce();
        } else {
            $donationRepoProphecy->allocateMatchFunds($donation)->shouldNotBeCalled();
        }

        $entityManagerProphecy = $this->prophesize(RetrySafeEntityManager::class);

        if ($donationPersisted) {
            if (!$skipEmExpectations) {
                if ($donationPushed) {
                    // Persist + flush happens twice. See code by comment "Must persist
                    // before Stripe work to have ID available."
                    $entityManagerProphecy->persistWithoutRetries($donation)->shouldBeCalledTimes(2);
                    $entityManagerProphecy->flush()->shouldBeCalledTimes(2);
                } else {
                    $entityManagerProphecy->persistWithoutRetries($donation)->shouldBeCalledOnce();
                    $entityManagerProphecy->flush()->shouldBeCalledOnce();
                }
            }
        } else {
            $entityManagerProphecy->persistWithoutRetries($donation)->shouldNotBeCalled();
            $entityManagerProphecy->flush()->shouldNotBeCalled();
        }

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(RetrySafeEntityManager::class, $entityManagerProphecy->reveal());

        return $app;
    }

    private function encode(Donation $donation): string
    {
        $donationArray = $donation->toApiModel();

        return json_encode($donationArray);
    }

    /**
     * One-time, artifically long hard-coded token attached here, so we don't
     * need live code just for MatchBot to issue ID tokens only for unit tests.
     * Token is for Stripe Customer cus_aaaaaaaaaaaa11.
     */
    private function addDummyPersonAuth(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withHeader('x-tbg-auth', TestData\Identity::getTestIdentityTokenIncomplete());
    }

    private function getTestDonation(
        bool $campaignOpen,
        bool $campaignMatched,
        bool $minimalSetupData = false,
        string $currencyCode = 'GBP',
    ): Donation {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setSalesforceId('567CharitySFID');
        $charity->setName('Create test charity');
        $charity->setStripeAccountId('unitTest_stripeAccount_123');

        $campaign = new Campaign(charity: $charity);
        $campaign->setName('123CampaignName');
        $campaign->setIsMatched($campaignMatched);
        $campaign->setSalesforceId('123CampaignId12345');
        $campaign->setStartDate((new \DateTime())->sub(new \DateInterval('P2D')));
        if ($campaignOpen) {
            $campaign->setEndDate((new \DateTime())->add(new \DateInterval('P1D')));
        } else {
            $campaign->setEndDate((new \DateTime())->sub(new \DateInterval('P1D')));
        }

        /** @psalm-suppress DeprecatedMethod */
        $donation = Donation::emptyTestDonation(amount: '12.00', currencyCode: $currencyCode);
        $donation->setCampaign(TestCase::getMinimalCampaign());

        if (!$minimalSetupData) {
            $donation->update(giftAid: false);
            $donation->setCharityComms(true);
            $donation->setChampionComms(false);
            $donation->setTbgComms(false);
        }

        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCampaign($campaign);
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));
        $donation->setDonorCountryCode('GB');
        $donation->setTipAmount('1.11');
        $donation->setTransactionId('pi_stripe_pending_123');
        $donation->setPspCustomerId('cus_aaaaaaaaaaaa11');
        $donation->setCharityFee('0.43');



        return $donation;
    }

    /**
     * Withdrawal for exactly £8 for now. In this class, typically a partial match.
     */
    private static function someWithdrawal(Donation $donation): FundingWithdrawal
    {
        $withdrawal = new FundingWithdrawal(self::someCampaignFunding());
        $withdrawal->setAmount('8.00');
        $withdrawal->setDonation($donation);

        return $withdrawal;
    }

    private static function someCampaignFunding(): CampaignFunding
    {
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setAmount('8.00');
        $campaignFunding->setCurrencyCode('GBP');

        return $campaignFunding;
    }
}
