<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
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
            ->willThrow(new UnexpectedValueException('Required boolean fields not set'));

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = '{}'; // Valid JSON but `buildFromApiRequest()` will error
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Required boolean fields not set',
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

        $data = json_encode($donation->toApiModel(true));
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

        $data = json_encode($donation->toApiModel(true));
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

        // This and several subsequent Prophecy calls are defined in order to assert that they are *not* called in
        // this error case, because we bail out before they would normally happen.
        $donationRepoProphecy->push(Argument::type(Donation::class), Argument::type('bool'))->shouldNotBeCalled();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->pull(Argument::type(Campaign::class))
            ->willReturn($donation->getCampaign())
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(Campaign::class)
            ->willReturn($campaignRepoProphecy->reveal())
            ->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create(Argument::any())->shouldNotBeCalled();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertEquals('SERVER_ERROR', $payloadArray['error']['type']);
        $this->assertEquals('Could not make Stripe Payment Intent (A)', $payloadArray['error']['description']);
    }

    public function testSuccessWithStripeAccountIDMissingInitiallyButFoundOnRefetch(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPsp('stripe');
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
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1200,
            'currency' => 'gbp',
            'metadata' => [
                'campaignId' => '123CampaignId',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'coreDonationGiftAid' => false,
                'environment' => getenv('APP_ENV'),
                'isGiftAid' => false,
                'matchedAmount' => '8.00',
                'optInCharityEmail' => true,
                'optInTbgEmail' => false,
                'tbgTipGiftAid' => false,
            ],
            'transfer_data' => [
                'amount' => 1166,
                'destination' => 'unitTest_newStripeAccount_456',
            ],
        ];
        // Most properites we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent456_id',
            'object' => 'payment_intent',
            'amount' => 1200,
            'client_secret' => 'pi_dummySecret_456',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
        $this->assertEquals('Pending', $payloadArray['donation']['status']);
        $this->assertEquals('stripe', $payloadArray['donation']['psp']);
        $this->assertEquals('pi_dummyIntent456_id', $payloadArray['donation']['transactionId']);
        $this->assertEquals('pi_dummySecret_456', $payloadArray['donation']['clientSecret']);
    }

    public function testSuccessWithMatchedCampaignUsingEnthuse(): void
    {
        $donation = $this->getTestDonation(true, true);

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
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
        $this->assertEquals('Pending', $payloadArray['donation']['status']);
        $this->assertNull($payloadArray['donation']['transactionId']);
        $this->assertNull($payloadArray['donation']['clientSecret']);
    }

    public function testSuccessWithMatchedCampaignUsingStripe(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPsp('stripe');

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
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $expectedPaymentIntentArgs = [
            'amount' => 1200,
            'currency' => 'gbp',
            'metadata' => [
                'campaignId' => '123CampaignId',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'coreDonationGiftAid' => false,
                'environment' => getenv('APP_ENV'),
                'isGiftAid' => false,
                'matchedAmount' => '8.00',
                'optInCharityEmail' => true,
                'optInTbgEmail' => false,
                'tbgTipGiftAid' => false,
            ],
            'transfer_data' => [
                'amount' => 1166,
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];
        // Most properites we don't use omitted.
        // See https://stripe.com/docs/api/payment_intents/object
        $paymentIntentMockResult = (object) [
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1200,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ];

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $stripePaymentIntentsProphecy->create($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy->reveal();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('8', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
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
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
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
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertNull($payloadArray['donation']['giftAid']);
        $this->assertNull($payloadArray['donation']['optInCharityEmail']);
        $this->assertNull($payloadArray['donation']['optInTbgEmail']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
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
        $donation->setAmount('12.00');
        $donation->setCampaign($campaign);
        $donation->setPsp('enthuse');
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));
        $donation->setDonorCountryCode('GB');
        $donation->setTipAmount('1.11');

        if (!$minimalSetupData) {
            $donation->setCharityComms(true);
            $donation->setGiftAid(false);
            $donation->setTbgComms(false);
        }

        return $donation;
    }
}
