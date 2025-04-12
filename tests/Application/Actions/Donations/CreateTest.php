<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Los\RateLimit\Exception\MissingRequirement;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Messenger\DonationUpserted;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Fund;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\Fund as FundEntity;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\FundType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use Override;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Slim\App;
use Slim\Exception\HttpUnauthorizedException;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use UnexpectedValueException;

class CreateTest extends TestCase
{
    public const string PSPCUSTOMERID = 'cus_aaaaaaaaaaaa11';
    public const string DONATION_UUID = '1822c3b6-b405-11ef-9766-63f04fc63fc3';
    private static array $somePaymentIntentArgs;
    /**
     * @var PaymentIntent Mock result, most properites we don't use omitted.
     * @link https://stripe.com/docs/api/payment_intents/object
     */
    private static PaymentIntent $somePaymentIntentResult;

    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;
    /**
     * @var mixed|object|ClockInterface
     */
    private ClockInterface $previousClock;
    private \DateTimeImmutable $now;
    private ?Campaign $campaign = null;

    /** @var ObjectProphecy<CampaignRepository> */
    private ObjectProphecy $campaignRepositoryProphecy;

    public function setUp(): void
    {
        parent::setUp();

        $this->now = new \DateTimeImmutable('2024-12-24'); // specific date doesn't matter.

        static::$somePaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => self::PSPCUSTOMERID,
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '0.00',
                'stripeFeeRechargeGross' => '0.43', // Includes Gift Aid processing fee
                'stripeFeeRechargeNet' => '0.43',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
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

        $this->campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $testCase = $this;
        $this->campaignRepositoryProphecy->findOneBy(['salesforceId' => '123CampaignId12345'])->will(fn() => $testCase->campaign);
        $this->diContainer()->set(CampaignRepository::class, $this->campaignRepositoryProphecy->reveal());
        $this->diContainer()->set(DonorAccountRepository::class, $this->createStub(DonorAccountRepository::class));
        $this->diContainer()->set(FundRepository::class, $this->prophesize(FundRepository::class)->reveal());


        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);

        $this->previousClock = $this->diContainer()->get(ClockInterface::class);
        $this->diContainer()->set(ClockInterface::class, new MockClock($this->now));
    }

    #[Override] public function tearDown(): void
    {
        $this->diContainer()->set(ClockInterface::class, $this->previousClock);
    }

    /**
     * While we don't test it separately, we now expect invalid `paymentMethodType` to be caught by the
     * same condition, as the property is now an enum.
     */
    public function testDeserialiseError(): void
    {
        $app = $this->getAppInstance();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());

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

        $this->setFakeDonationRepoForBuildFromApiRequestOnly();

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
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class), Argument::type(PersonId::class), Argument::type(DonationService::class))
            ->willReturn($donationToReturn);
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();
        $donationRepoProphecy->push(Argument::type(DonationUpserted::class));

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->updateFromSf(Argument::type(Campaign::class))
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->isOpen()->willReturn(true);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::any())->shouldNotBeCalled();

        $this->diContainer()->set(CampaignRepository::class, $campaignRepoProphecy->reveal());
        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class), Argument::type(PersonId::class), Argument::type(DonationService::class))
            ->willThrow(new UnexpectedValueException('Currency CAD is invalid for campaign'));
        $donationRepoProphecy->push(Argument::type(DonationUpserted::class));

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

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
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class), Argument::type(PersonId::class), Argument::type(DonationService::class))
            ->shouldNotBeCalled();
        $donationRepoProphecy->push(Argument::type(DonationUpserted::class));
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

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
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->updateFromSf(Argument::type(Campaign::class))
            ->will(/**
             * @param array{0: Campaign} $args
             */
                fn (array $args) => $args[0]->getCharity()
                    ->setStripeAccountId('unitTest_newStripeAccount_456')
            );

        // Need to override stock EM to get campaign repo behaviour
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->isOpen()->willReturn(true);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => self::PSPCUSTOMERID,
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.38',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
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
        $this->prophesizeCustomerSession($stripeProphecy);

        $this->campaignRepositoryProphecy = $campaignRepoProphecy;
        $this->diContainer()->set(CampaignRepository::class, $this->campaignRepositoryProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $this->assertEquals(self::DONATION_UUID, $payloadArray['donation']['donationId']);
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
        $expectedPaymentIntentArgs = [
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
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
            'customer' => self::PSPCUSTOMERID,
        ];

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($stripeProphecy);


        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $this->assertEquals(self::DONATION_UUID, $payloadArray['donation']['donationId']);
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
        $donation->setPspCustomerId(self::PSPCUSTOMERID);

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
        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'customer' => self::PSPCUSTOMERID,
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic',
            'metadata' => [
                'campaignId' => '123CampaignId12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.38',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.00',
                'tipAmount' => '1.11',
            ],
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
        $this->prophesizeCustomerSession($stripeProphecy);

        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $this->assertEquals(self::DONATION_UUID, $payloadArray['donation']['donationId']);
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
        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->shouldNotBeCalled();

        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

        $data = json_encode($donation->toFrontEndApiModel(), \JSON_THROW_ON_ERROR);
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
        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->shouldNotBeCalled();

        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

        $data = json_encode($donation->toFrontEndApiModel(), \JSON_THROW_ON_ERROR);
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

        $this->campaignRepositoryProphecy->pullNewFromSf($donation->getCampaign()->getSalesforceId())->willReturn($this->campaign);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class), Argument::type(PersonId::class), Argument::type(DonationService::class))
        ->willThrow(UniqueConstraintViolationException::class);

        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->isOpen()->willReturn(true);
        // These are called once after initial ID setup and once after Stripe fields added.
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);


        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($stripeProphecy);

        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $this->assertEquals(0.08, $payloadArray['donation']['charityFeeVat']);
        $this->assertEquals('GB', $payloadArray['donation']['countryCode']);
        $this->assertEquals('12', $payloadArray['donation']['donationAmount']);
        $this->assertTrue(Uuid::isValid((string) $payloadArray['donation']['donationId']));
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
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
        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(self::$somePaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($stripeProphecy);


        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $this->assertEquals(self::DONATION_UUID, $payloadArray['donation']['donationId']);
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
        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(self::$somePaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($stripeProphecy);

        $this->diContainer()->set(Stripe::class, $stripeProphecy->reveal());

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
        $this->assertEquals(self::DONATION_UUID, $payloadArray['donation']['donationId']);
        $this->assertEquals('0', $payloadArray['donation']['matchReservedAmount']);
        $this->assertEquals('1.11', $payloadArray['donation']['tipAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId12345', $payloadArray['donation']['projectId']);
    }

    public function testErrorWhenAllDbPersistCallsFail(): void
    {
        $donation = $this->getTestDonation(true, true, true);

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: false,
            donationPushed: false,
            donationMatched: false,
            donation: $donation,
        );
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->isOpen()->willReturn(true);
        $entityManagerProphecy->persist(Argument::type(Donation::class))
            ->willThrow($this->prophesize(DBALServerException::class)->reveal())
            ->shouldBeCalledTimes(3); // DonationService::MAX_RETRY_COUNT
        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $this->diContainer()->set(ClockInterface::class, new MockClock($this->now));
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', TestData\Identity::getTestPersonNewDonationEndpoint(), $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(500, $response->getStatusCode());

        /** @var array $payloadArray */
        $payloadArray = json_decode($payload, true);

        $this->assertEquals(['error' => [
            'type' => 'SERVER_ERROR',
            'description' => 'Could not make Stripe Payment Intent (D)',
        ]], $payloadArray);
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
        $this->setFakeDonationRepoForBuildFromApiRequestOnly();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class), Argument::type(PersonId::class), Argument::type(DonationService::class))
            ->willReturn($donation);

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findOneBy(['salesforceId' => '123CampaignId12345'])->willReturn($this->campaign);

        /**
         * @see \MatchBot\IntegrationTests\DonationRepositoryTest for more granular checks of what's
         * in the envelope. There isn't much variation in what we dispatch so it's not critical to
         * repeat these checks in every test, but we do want to check we are dispatching the
         * expected number of times.
         */
        if ($donationPushed) {
            $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldBeCalledOnce()
                ->willReturnArgument();
        } else {
            $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldNotBeCalled();
        }

        if ($donationMatched) {
            $donationRepoProphecy->allocateMatchFunds($donation)->shouldBeCalledOnce();
        } else {
            $donationRepoProphecy->allocateMatchFunds($donation)->shouldNotBeCalled();
        }

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->isOpen()->willReturn(true);

        if ($donationPersisted) {
            if (!$skipEmExpectations) {
                if ($donationPushed) {
                    // Persist + flush happens twice. See code by comment "Must persist
                    // before Stripe work to have ID available."
                    $entityManagerProphecy->persist($donation)->shouldBeCalledTimes(2);
                    $entityManagerProphecy->flush()->shouldBeCalledTimes(2);
                } else {
                    $entityManagerProphecy->persist($donation)->shouldBeCalledOnce();
                    $entityManagerProphecy->flush()->shouldBeCalledOnce();
                }
            }
        } else {
            $entityManagerProphecy->persist($donation)->shouldNotBeCalled();
            $entityManagerProphecy->flush()->shouldNotBeCalled();
        }

        $this->diContainer()->set(CampaignRepository::class, $campaignRepoProphecy->reveal());
        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $this->diContainer()->set(RoutableMessageBus::class, $this->messageBusProphecy->reveal());

        return $app;
    }

    private function encode(Donation $donation): string
    {
        $donationArray = $donation->toFrontEndApiModel();

        return json_encode($donationArray, \JSON_THROW_ON_ERROR);
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

        $campaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign('123CampaignId12345'), charity: $charity);
        $campaign->setName('123CampaignName');
        $campaign->setIsMatched($campaignMatched);
        $campaign->setStartDate($this->now->sub(new \DateInterval('P2D')));
        if ($campaignOpen) {
            $campaign->setEndDate($this->now->add(new \DateInterval('P1D')));
        } else {
            $campaign->setEndDate($this->now->sub(new \DateInterval('P1D')));
        }
        $this->campaign = $campaign;

        $donation = TestCase::someDonation(amount: '12.00', currencyCode: $currencyCode);
        $donation->setCampaign(TestCase::getMinimalCampaign());

        if (!$minimalSetupData) {
            $donation->update(giftAid: false);
            $donation->setCharityComms(true);
            $donation->setChampionComms(false);
            $donation->setTbgComms(false);
        }

        $donation->createdNow(); // Call same create/update time initialisers as lifecycle hooks
        $donation->setCampaign($campaign);
        $donation->setUuid(Uuid::fromString(self::DONATION_UUID));
        $donation->setDonorCountryCode('GB');
        $donation->setTipAmount('1.11');
        $donation->setTransactionId('pi_stripe_pending_123');
        $donation->setPspCustomerId(self::PSPCUSTOMERID);
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
        return new CampaignFunding(
            fund: new FundEntity('GBP', 'some pledge', null, FundType::Pledge),
            amount: '8.00',
            amountAvailable: '8.00',
        );
    }

    /**
     * @param ObjectProphecy<Stripe> $stripeProphecy
     * @return void
     */
    public function prophesizeCustomerSession(ObjectProphecy $stripeProphecy): void
    {
        $customerSession = new CustomerSession();
        $customerSession->client_secret = 'customer_session_client_secret';
        $stripeProphecy->createCustomerSession(StripeCustomerId::of(self::PSPCUSTOMERID))
            ->willReturn($customerSession);
    }

    public function setFakeDonationRepoForBuildFromApiRequestOnly(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::cetera())
            ->will(fn($args) => $args[2]->buildFromApiRequest($args[0], $args[1]));
        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
    }
}
