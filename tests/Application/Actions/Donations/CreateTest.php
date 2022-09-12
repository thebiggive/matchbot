<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use LosMiddleware\RateLimit\Exception\MissingRequirement;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Actions\Hooks\Stripe;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Ramsey\Uuid\Uuid;
use Stripe\Service\PaymentIntentService;
use Stripe\Service\CustomerService;
use Stripe\StripeClient;
use UnexpectedValueException;

class CreateTest extends TestCase
{
    public function testDeserialiseError(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = '{"not-good-json';

        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Donation Create data deserialise error',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testModelError(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(new DonationCreate()) // empty DonationCreate == {} deserialised.
            ->willThrow(new UnexpectedValueException('Required field "projectId" not set'));

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = '{}'; // Valid JSON but `buildFromApiRequest()` will error
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Required field "projectId" not set',
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

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Campaign 123CampaignId is not open',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testValidDataForMatchedCampaignWhenFundLockNotAcquired(): void
    {
        $donation = $this->getTestDonation(true, true);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->shouldNotBeCalled();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))
            ->willThrow(DomainLockContentionException::class)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(503, ['error' => [
            'type' => 'SERVER_ERROR',
            'description' => 'Fund resource locked',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(503, $response->getStatusCode());
    }

    public function testStripeWithMissingStripeAccountID(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPsp('stripe');
        $donation->getCampaign()->getCharity()->setStripeAccountId(null);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus('Pending');

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
        $campaignRepoProphecy->pull(Argument::type(Campaign::class))
            ->willReturn($donation->getCampaign())
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Campaign::class)
            ->willReturn($campaignRepoProphecy->reveal())
            ->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create(Argument::any())->shouldNotBeCalled();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertEquals('SERVER_ERROR', $payloadArray['error']['type']);
        $this->assertEquals('Could not make Stripe Payment Intent (A)', $payloadArray['error']['description']);
    }

    public function testCurrencyMismatch(): void
    {
        $donation = $this->getTestDonation(true, false, true);
        $donation->setCurrencyCode('CAD');

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willThrow(new UnexpectedValueException('Currency CAD is invalid for campaign'));
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

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

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest(
            'POST',
            '/v1/donations',
            $data,
            ['HTTP_ACCEPT' => 'application/json'], // Un-set forwarded IP header.
        );
        $app->handle($request); // Rate limit middleware should bail out.
    }

    public function testSuccessWithStripeAccountIDMissingInitiallyButFoundOnRefetch(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPsp('stripe');
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.
        $donation->getCampaign()->getCharity()->setStripeAccountId(null);

        $fundingWithdrawalForMatch = new FundingWithdrawal();
        $fundingWithdrawalForMatch->setAmount('8.00'); // Partial match
        $fundingWithdrawalForMatch->setDonation($donation);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus('Pending');
        $donationToReturn->addFundingWithdrawal($fundingWithdrawalForMatch);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donationToReturn);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();

        // Cloning & use of new objects is necessary here, so we don't set
        // the Stripe value on the copy of the object which is meant to be
        // missing it for the test to follow that logic branch.
        $charityWhichNowHasStripeAccountID = clone $donation->getCampaign()->getCharity();
        $charityWhichNowHasStripeAccountID
            ->setStripeAccountId('unitTest_newStripeAccount_456');
        $campaignWithCharityWhichNowHasStripeAccountID = clone  $donation->getCampaign();
        $campaignWithCharityWhichNowHasStripeAccountID->setCharity($charityWhichNowHasStripeAccountID);

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->pull(Argument::type(Campaign::class))
            ->willReturn($campaignWithCharityWhichNowHasStripeAccountID)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Campaign::class)
            ->willReturn($campaignRepoProphecy->reveal())
            ->shouldBeCalledOnce();
        // These are called once after initial ID setup and once after Stripe fields added.
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedCustomerArgs = [
            'address' => [
                'line1' => $donation->getDonorHomeAddressLine1(),
                'postal_code' => $donation->getDonorHomePostcode(),
                'country' => $donation->getDonorCountryCode(),
            ],
            'description' => null,
            'email' => $donation->getDonorEmailAddress(),
            'metadata' => [],
            'name' => $donation->getDonorFirstName() . ' ' . $donation->getDonorLastName(),
            'phone' => null,
        ];

        $customerMockResult = (object) [
            'id' => 'cus_4QFGmAJFVeA4oD',
        ];

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->create($expectedCustomerArgs)
            ->willReturn($customerMockResult)
            ->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId',
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
            'statement_descriptor' => 'The Big Give Create te',
            'application_fee_amount' => 149,
            'on_behalf_of' => 'unitTest_newStripeAccount_456',
            'transfer_data' => [
                'destination' => 'unitTest_newStripeAccount_456',
            ],
        ];
        // Most properties we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent456_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_456',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

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
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
        $this->assertEquals('Pending', $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent456_id', $payloadArray['donation']['transactionId']);
        $this->assertEquals('pi_dummySecret_456', $payloadArray['donation']['clientSecret']);
    }

    public function testSuccessWithMatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPsp('stripe');
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.

        $fundingWithdrawalForMatch = new FundingWithdrawal();
        $fundingWithdrawalForMatch->setAmount('8.00'); // Partial match
        $fundingWithdrawalForMatch->setDonation($donation);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus('Pending');
        $donationToReturn->addFundingWithdrawal($fundingWithdrawalForMatch);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donationToReturn);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        // These are called once after initial ID setup and once after Stripe fields added.
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedCustomerArgs = [
            'address' => [
                'line1' => $donation->getDonorHomeAddressLine1(),
                'postal_code' => $donation->getDonorHomePostcode(),
                'country' => $donation->getDonorCountryCode(),
            ],
            'description' => null,
            'email' => $donation->getDonorEmailAddress(),
            'metadata' => [],
            'name' => $donation->getDonorFirstName() . ' ' . $donation->getDonorLastName(),
            'phone' => null,
        ];

        $customerMockResult = (object) [
            'id' => 'cus_4QFGmAJFVeA4oD',
        ];

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->create($expectedCustomerArgs)
            ->willReturn($customerMockResult)
            ->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId',
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
            'statement_descriptor' => 'The Big Give Create te',
            'application_fee_amount' => 149,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];
        // Most properties we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

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
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
        $this->assertEquals('Pending', $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
        $this->assertEquals('pi_dummySecret_123', $payloadArray['donation']['clientSecret']);
    }

    public function testSuccessWithMatchedCampaignAndInitialCampaignDuplicateError(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPsp('stripe');
        $donation->setCharityFee('0.38'); // Calculator is tested elsewhere.

        $fundingWithdrawalForMatch = new FundingWithdrawal();
        $fundingWithdrawalForMatch->setAmount('8.00'); // Partial match
        $fundingWithdrawalForMatch->setDonation($donation);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus('Pending');
        $donationToReturn->addFundingWithdrawal($fundingWithdrawalForMatch);

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

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        // These are called once after initial ID setup and once after Stripe fields added.
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedCustomerArgs = [
            'address' => [
                'line1' => $donation->getDonorHomeAddressLine1(),
                'postal_code' => $donation->getDonorHomePostcode(),
                'country' => $donation->getDonorCountryCode(),
            ],
            'description' => null,
            'email' => $donation->getDonorEmailAddress(),
            'metadata' => [],
            'name' => $donation->getDonorFirstName() . ' ' . $donation->getDonorLastName(),
            'phone' => null,
        ];

        $customerMockResult = (object) [
            'id' => 'cus_4QFGmAJFVeA4oD',
        ];

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->create($expectedCustomerArgs)
            ->willReturn($customerMockResult)
            ->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId',
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
            'statement_descriptor' => 'The Big Give Create te',
            'application_fee_amount' => 149,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];
        // Most properties we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

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
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
        $this->assertEquals('Pending', $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
        $this->assertEquals('pi_dummySecret_123', $payloadArray['donation']['clientSecret']);
    }

    public function testSuccessWithUnmatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, false);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        // Persist + flush happens twice. See code by comment "Must persist
        // before Stripe work to have ID available."
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedCustomerArgs = [
            'address' => [
                'line1' => $donation->getDonorHomeAddressLine1(),
                'postal_code' => $donation->getDonorHomePostcode(),
                'country' => $donation->getDonorCountryCode(),
            ],
            'description' => null,
            'email' => $donation->getDonorEmailAddress(),
            'metadata' => [],
            'name' => $donation->getDonorFirstName() . ' ' . $donation->getDonorLastName(),
            'phone' => null,
        ];

        $customerMockResult = (object) [
            'id' => 'cus_4QFGmAJFVeA4oD',
        ];

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->create($expectedCustomerArgs)
            ->willReturn($customerMockResult)
            ->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId',
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
            'statement_descriptor' => 'The Big Give Create te',
            'application_fee_amount' => 154,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        // Most properties we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

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
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
    }

    /**
     * Use unmatched campaign in previous test but also omit all donor-supplied
     * detail except donation and tip amount, to test new 2-step Create setup.
     */
    public function testSuccessWithMinimalData()
    {
        $donation = $this->getTestDonation(true, false, true);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        // Persist + flush happens twice. See code by comment "Must persist
        // before Stripe work to have ID available."
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedCustomerArgs = [
            'address' => [
                'line1' => $donation->getDonorHomeAddressLine1(),
                'postal_code' => $donation->getDonorHomePostcode(),
                'country' => $donation->getDonorCountryCode(),
            ],
            'description' => null,
            'email' => $donation->getDonorEmailAddress(),
            'metadata' => [],
            'name' => $donation->getDonorFirstName() . ' ' . $donation->getDonorLastName(),
            'phone' => null,
        ];

        $customerMockResult = (object) [
            'id' => 'cus_4QFGmAJFVeA4oD',
        ];

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->create($expectedCustomerArgs)
            ->willReturn($customerMockResult)
            ->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation 12345678-1234-1234-1234-1234567890ab to Create test charity',
            'metadata' => [
                'campaignId' => '123CampaignId',
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
            'statement_descriptor' => 'The Big Give Create te',
            'application_fee_amount' => 154,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        // Most properties we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = $this->encodeWithDummyCaptcha($donation);
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertNotEmpty($payloadArray['donation']['createdTime']);
        $this->assertNull($payloadArray['donation']['giftAid']);
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
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
    }

    private function encodeWithDummyCaptcha(Donation $donation): string
    {
        $donationArray = $donation->toApiModel();
        $donationArray['creationRecaptchaCode'] = 'good response';

        return json_encode($donationArray);
    }

    private function getTestDonation(
        bool $campaignOpen,
        bool $campaignMatched,
        bool $minimalSetupData = false
    ): Donation {
        $charity = new Charity();
        $charity->setDonateLinkId('567CharitySFID');
        $charity->setName('Create test charity');
        $charity->setStripeAccountId('unitTest_stripeAccount_123');

        $campaign = new Campaign();
        $campaign->setName('123CampaignName');
        $campaign->setCharity($charity);
        $campaign->setIsMatched($campaignMatched);
        $campaign->setSalesforceId('123CampaignId');
        $campaign->setStartDate((new \DateTime())->sub(new \DateInterval('P2D')));
        if ($campaignOpen) {
            $campaign->setEndDate((new \DateTime())->add(new \DateInterval('P1D')));
        } else {
            $campaign->setEndDate((new \DateTime())->sub(new \DateInterval('P1D')));
        }

        $donation = new Donation();
        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCurrencyCode('GBP');
        $donation->setAmount('12.00');
        $donation->setCampaign($campaign);
        $donation->setPsp('stripe');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));
        $donation->setDonorCountryCode('GB');
        $donation->setTipAmount('1.11');
        $donation->setCharityFee('0.43');

        if (!$minimalSetupData) {
            $donation->setCharityComms(true);
            $donation->setChampionComms(false);
            $donation->setGiftAid(false);
            $donation->setTbgComms(false);
        }

        return $donation;
    }
}
