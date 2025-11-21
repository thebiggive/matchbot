<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\ORM\UnitOfWork;
use Los\RateLimit\Exception\MissingRequirement;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Environment;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Messenger\DonationUpserted;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\Fund as FundEntity;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\FundType;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use MatchBot\Tests\TestData\Identity;
use Override;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Slim\App;
use Slim\Exception\HttpUnauthorizedException;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

class CreateTest extends TestCase
{
    public const string PSPCUSTOMERID = 'cus_aaaaaaaaaaaa11';
    public const string DONATION_UUID = '1822c3b6-b405-11ef-9766-63f04fc63fc3';

    /** @var array<string, mixed> */
    private static array $somePaymentIntentArgs;
    /**
     * @var PaymentIntent Mock result, most properites we don't use omitted.
     * @link https://stripe.com/docs/api/payment_intents/object
     */
    private static PaymentIntent $somePaymentIntentResult;

    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;

    private ClockInterface $previousClock;
    private \DateTimeImmutable $now;
    private ?Campaign $campaign = null;

    /** @var ObjectProphecy<Allocator> */
    private ObjectProphecy $allocatorProphecy;

    /** @var ObjectProphecy<CampaignRepository> */
    private ObjectProphecy $campaignRepositoryProphecy;

    /** @var ObjectProphecy<DonationRepository> */
    private ObjectProphecy $donationRepoProphecy;

    /** @var ObjectProphecy<Stripe>  */
    private ObjectProphecy $stripeProphecy;

    /** @var ObjectProphecy<EntityManagerInterface>  */
    private ObjectProphecy $entityManagerProphecy;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->now = new \DateTimeImmutable('2024-12-24'); // specific date doesn't matter.

        self::$somePaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'always',
            ],
            'customer' => self::PSPCUSTOMERID,
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic_async',
            'metadata' => [
                'campaignId' => '123CAmPAIGNID12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '0.00',
                'stripeFeeRechargeGross' => '0.46', // Includes Gift Aid processing fee
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.08',
                'tipAmount' => '1.11',
            ],
            'statement_descriptor' => 'Big Give Create test c',
            'statement_descriptor_suffix' => 'Big Give Create test c',
            'application_fee_amount' => 157,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        self::$somePaymentIntentResult = new PaymentIntent([
            'id' => 'pi_dummyIntent_id',
            'object' => 'payment_intent',
            'amount' => 1311,
            'client_secret' => 'pi_dummySecret_123',
            'confirmation_method' => 'automatic',
            'currency' => 'gbp',
        ]);

        $this->campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $testCase = $this;
        $this->campaignRepositoryProphecy->findOneBy(['salesforceId' => '123CAmPAIGNID12345'])->will(fn() => $testCase->campaign);

        $configurationProphecy = $this->prophesize(\Doctrine\ORM\Configuration::class);
        $config = $configurationProphecy->reveal();
        $configurationProphecy->getResultCache()->willReturn($this->createStub(CacheItemPoolInterface::class));

        $emptyUow = $this->prophesize(UnitOfWork::class);
        $emptyUow->computeChangeSets()->willReturn(null); // void
        $emptyUow->hasPendingInsertions()->willReturn(false);
        $emptyUow->getIdentityMap()->willReturn([]);

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $this->entityManagerProphecy->getConfiguration()->willReturn($config);
        $this->entityManagerProphecy->getUnitOfWork()->willReturn($emptyUow->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $this->entityManagerProphecy->reveal());

        $this->diContainer()->set(CampaignRepository::class, $this->campaignRepositoryProphecy->reveal());
        $this->diContainer()->set(DonorAccountRepository::class, $this->createStub(DonorAccountRepository::class));
        $this->diContainer()->set(FundRepository::class, $this->prophesize(FundRepository::class)->reveal());
        $this->donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $this->diContainer()->set(DonationRepository::class, $this->donationRepoProphecy->reveal());

        $this->allocatorProphecy = $this->prophesize(Allocator::class);
        $this->diContainer()->set(Allocator::class, $this->allocatorProphecy->reveal());

        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

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

        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Donation Create data deserialise error',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCampaignClosed(): void
    {
        $donation = $this->getTestDonation(false, false);

        $app = $this->getAppInstance();

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Campaign 123CAmPAIGNID12345 is not open',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testStripeWithMissingStripeAccountID(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->getCampaign()->getCharity()->setStripeAccountId(null);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatusForTest(DonationStatus::Pending);

        $app = $this->getAppInstance();

        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();

        $this->donationRepoProphecy->push(Argument::type(DonationUpserted::class));

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->updateFromSf(Argument::type(Campaign::class))
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->findOneBy(['salesforceId' => '123CAmPAIGNID12345'])->willReturn($donation->getCampaign());

        $this->entityManagerProphecy->isOpen()->willReturn(true);
        $this->entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $this->entityManagerProphecy->flush()->shouldBeCalledOnce();
        $this->entityManagerProphecy->beginTransaction()->willReturn(null);
        $this->entityManagerProphecy->commit()->willReturn(null);

        $this->stripeProphecy->createPaymentIntent(Argument::any())->shouldNotBeCalled();

        $this->diContainer()->set(Allocator::class, $allocatorProphecy->reveal());
        $this->diContainer()->set(CampaignRepository::class, $campaignRepoProphecy->reveal());
        $this->diContainer()->set(DonationRepository::class, $this->donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $this->entityManagerProphecy->reveal());
        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(500, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        \assert(is_array($payloadArray));

        $this->assertSame('SERVER_ERROR', $payloadArray['error']['type']);
        $this->assertSame('Could not make Stripe Payment Intent (A)', $payloadArray['error']['description']);
    }

    public function testCurrencyMismatch(): void
    {
        $donation = $this->getTestDonation(true, false, true, 'SEK');

        $app = $this->getAppInstance();
        $this->donationRepoProphecy->push(Argument::type(DonationUpserted::class));

        $this->entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $this->entityManagerProphecy->flush()->shouldNotBeCalled();

        $this->diContainer()->set(DonationRepository::class, $this->donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $this->entityManagerProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Currency SEK is invalid for campaign',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
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
        $donationRepoProphecy->push(Argument::type(DonationUpserted::class));

        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $this->entityManagerProphecy->persist(Argument::type(Donation::class))->shouldNotBeCalled();
        $this->entityManagerProphecy->flush()->shouldNotBeCalled();

        $this->diContainer()->set(Allocator::class, $allocatorProphecy->reveal());
        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $this->entityManagerProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest(
            'POST',
            Identity::TEST_PERSON_NEW_DONATION_ENDPOINT,
            $data,
            ['HTTP_ACCEPT' => 'application/json'], // Un-set forwarded IP header.
        );
        $app->handle($this->addDummyPersonAuth($request)); // Rate limit middleware should bail out.
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testSuccessWithStripeAccountIDMissingInitiallyButFoundOnRefetch(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->getCampaign()->getCharity()->setStripeAccountId(null);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatusForTest(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: true,
            donation: $donationToReturn,
            skipEmExpectations: true,
        );
        $this->setupFakeDonationProvider($donation);

        $this->campaignRepositoryProphecy->updateFromSf(Argument::type(Campaign::class))
            ->will(/**
             * @param array{0: Campaign} $args
             */
                fn (array $args) => $args[0]->getCharity()
                    ->setStripeAccountId('unitTest_newStripeAccount_456')
            );

        // Need to override stock EM to get campaign repo behaviour
        $this->entityManagerProphecy->isOpen()->willReturn(true);
        $this->entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $this->entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'always',
            ],
            'customer' => self::PSPCUSTOMERID,
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic_async',
            'metadata' => [
                'campaignId' => '123CAmPAIGNID12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.46',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.08',
                'tipAmount' => '1.11',
            ],
            'statement_descriptor' => 'Big Give Create test c',
            'statement_descriptor_suffix' => 'Big Give Create test c',
            'application_fee_amount' => 157,
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

        $this->stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn($paymentIntentMockResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($this->stripeProphecy);

        $this->diContainer()->set(CampaignRepository::class, $this->campaignRepositoryProphecy->reveal());
        $this->diContainer()->set(EntityManagerInterface::class, $this->entityManagerProphecy->reveal());
        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertSame(0.38, $payloadArray['donation']['charityFee']);
        $this->assertSame(0.08, $payloadArray['donation']['charityFeeVat']);
        $this->assertSame('GB', $payloadArray['donation']['countryCode']);
        $this->assertSame(12, $payloadArray['donation']['donationAmount']);
        $this->assertSame(self::DONATION_UUID, $payloadArray['donation']['donationId']);
        $this->assertSame(8, $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertSame(1.11, $payloadArray['donation']['tipAmount']);
        $this->assertSame('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertSame('123CAmPAIGNID12345', $payloadArray['donation']['projectId']);
        $this->assertSame(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertSame('stripe', $payloadArray['donation']['psp']);
        $this->assertSame('pi_dummyIntent456_id', $payloadArray['donation']['transactionId']);
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testSuccessWithMatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, true);

        $fundingWithdrawalForMatch = new FundingWithdrawal(self::someCampaignFunding(), $donation, '8.00' /* partial match */);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatusForTest(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal($fundingWithdrawalForMatch);

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: true,
            donation: $donationToReturn,
            skipEmExpectations: false,
        );
        $this->setupFakeDonationProvider($donation);

        $expectedPaymentIntentArgs = [
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'always',
            ],
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic_async',
            'metadata' => [
                'campaignId' => '123CAmPAIGNID12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.46',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.08',
                'tipAmount' => '1.11',
            ],
            'statement_descriptor' => 'Big Give Create test c',
            'statement_descriptor_suffix' => 'Big Give Create test c',
            'application_fee_amount' => 157,
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
            'customer' => self::PSPCUSTOMERID,
        ];

        $this->stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($this->stripeProphecy);


        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertSame(0.38, $payloadArray['donation']['charityFee']); // 1.5% + 20p.
        $this->assertSame(0.08, $payloadArray['donation']['charityFeeVat']);
        $this->assertSame('GB', $payloadArray['donation']['countryCode']);
        $this->assertSame(12, $payloadArray['donation']['donationAmount']);
        $this->assertSame(self::DONATION_UUID, $payloadArray['donation']['donationId']);
        $this->assertSame(8, $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertSame(1.11, $payloadArray['donation']['tipAmount']);
        $this->assertSame('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertSame('123CAmPAIGNID12345', $payloadArray['donation']['projectId']);
        $this->assertSame(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertSame('stripe', $payloadArray['donation']['psp']);
        $this->assertSame('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testSuccessWithMatchedCampaignAndPspCustomerId(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPspCustomerId(self::PSPCUSTOMERID);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatusForTest(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: true,
            donation: $donationToReturn,
            skipEmExpectations: false,
        );
        $this->setupFakeDonationProvider($donation);

        $expectedPaymentIntentArgs = [
            'amount' => 1311, // Pence including tip
            'currency' => 'gbp',
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'always',
            ],
            'customer' => self::PSPCUSTOMERID,
            'description' => 'Donation ' . self::DONATION_UUID . ' to Create test charity',
            'capture_method' => 'automatic_async',
            'metadata' => [
                'campaignId' => '123CAmPAIGNID12345',
                'campaignName' => '123CampaignName',
                'charityId' => '567CharitySFID',
                'charityName' => 'Create test charity',
                'donationId' => self::DONATION_UUID,
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => '8.00',
                'stripeFeeRechargeGross' => '0.46',
                'stripeFeeRechargeNet' => '0.38',
                'stripeFeeRechargeVat' => '0.08',
                'tipAmount' => '1.11',
            ],
            'statement_descriptor' => 'Big Give Create test c',
            'statement_descriptor_suffix' => 'Big Give Create test c',
            'application_fee_amount' => 157,
            'on_behalf_of' => 'unitTest_stripeAccount_123',
            'transfer_data' => [
                'destination' => 'unitTest_stripeAccount_123',
            ],
        ];

        $this->stripeProphecy->createPaymentIntent($expectedPaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($this->stripeProphecy);

        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $request = $this->createRequest(
            'POST',
            Identity::TEST_PERSON_NEW_DONATION_ENDPOINT,
            $this->encode($donation),
        );
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertSame(0.38, $payloadArray['donation']['charityFee']); // 1.5% + 20p.
        $this->assertSame(0.08, $payloadArray['donation']['charityFeeVat']);
        $this->assertSame('GB', $payloadArray['donation']['countryCode']);
        $this->assertSame(12, $payloadArray['donation']['donationAmount']);
        $this->assertSame(self::DONATION_UUID, $payloadArray['donation']['donationId']);
        $this->assertSame(8, $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertSame(1.11, $payloadArray['donation']['tipAmount']);
        $this->assertSame('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertSame('123CAmPAIGNID12345', $payloadArray['donation']['projectId']);
        $this->assertSame(DonationStatus::Pending->value, $payloadArray['donation']['status']);
        $this->assertSame('stripe', $payloadArray['donation']['psp']);
        $this->assertSame('pi_dummyIntent_id', $payloadArray['donation']['transactionId']);
    }

    public function testMatchedCampaignButWrongPersonInRoute(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        // Default test Customer ID is cus_aaaaaaaaaaaa11.
        $donation = $this->getTestDonation(true, true);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatusForTest(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: false,
            donationPushed: false,
            donationMatched: false,
            donation: $donationToReturn,
            skipEmExpectations: true,
        );
        $this->stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->shouldNotBeCalled();

        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = json_encode($donation->toFrontEndApiModel(), \JSON_THROW_ON_ERROR);
        // Don't match default test customer ID from body, in this path.
        $request = $this->createRequest('POST', '/v1/people/99999999-1234-1234-1234-1234567890zz/donations', $data);
        $app->handle($this->addDummyPersonAuth($request)); // Throws HttpUnauthorizedException.
    }

    public function testMatchedCampaignButWrongCustomerIdInBody(): void
    {
        $donation = $this->getTestDonation(true, true);
        $donation->setPspCustomerId('cus_zzaaaaaaaaaa99');

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatusForTest(DonationStatus::Pending);
        $donationToReturn->addFundingWithdrawal(self::someWithdrawal($donation));

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: false,
            donationPushed: false,
            donationMatched: false,
            donation: $donationToReturn,
            skipEmExpectations: false
        );
        $this->setupFakeDonationProvider($donation);

        $this->stripeProphecy->createPaymentIntent(Argument::type('array'))
            ->shouldNotBeCalled();

        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = json_encode($donation->toFrontEndApiModel(), \JSON_THROW_ON_ERROR);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);

        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Route customer ID cus_aaaaaaaaaaaa11 did not match cus_zzaaaaaaaaaa99 in donation body',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testSuccessWithUnmatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, false);

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: true,
            donationPushed: true,
            donationMatched: false,
            donation: $donation,
            skipEmExpectations: false,
        );
        $this->setupFakeDonationProvider($donation);

        $this->stripeProphecy->createPaymentIntent(self::$somePaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($this->stripeProphecy);


        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(201, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertSame(0.38, $payloadArray['donation']['charityFee']); // 1.5% + 20p.
        $this->assertSame(0.08, $payloadArray['donation']['charityFeeVat']);
        $this->assertSame('GB', $payloadArray['donation']['countryCode']);
        $this->assertSame(12, $payloadArray['donation']['donationAmount']);
        $this->assertSame(self::DONATION_UUID, $payloadArray['donation']['donationId']);
        $this->assertSame(0, $payloadArray['donation']['matchReservedAmount']);
        $this->assertTrue($payloadArray['donation']['optInCharityEmail']);
        $this->assertFalse($payloadArray['donation']['optInChampionEmail']);
        $this->assertFalse($payloadArray['donation']['optInTbgEmail']);
        $this->assertSame(1.11, $payloadArray['donation']['tipAmount']);
        $this->assertSame('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertSame('123CAmPAIGNID12345', $payloadArray['donation']['projectId']);
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
            skipEmExpectations: false,
        );
        $this->setupFakeDonationProvider($donation);

        $this->stripeProphecy->createPaymentIntent(self::$somePaymentIntentArgs)
            ->willReturn(self::$somePaymentIntentResult)
            ->shouldBeCalledOnce();
        $this->prophesizeCustomerSession($this->stripeProphecy);

        $this->diContainer()->set(Stripe::class, $this->stripeProphecy->reveal());

        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(201, $response->getStatusCode());

        /** @var array<string, string|numeric|boolean|array<array-key, mixed>> $payloadArray */
        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertNotEmpty($payloadArray['donation']['createdTime']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertNull($payloadArray['donation']['optInCharityEmail']);
        $this->assertNull($payloadArray['donation']['optInChampionEmail']);
        $this->assertNull($payloadArray['donation']['optInTbgEmail']);
        $this->assertSame(0.38, $payloadArray['donation']['charityFee']); // 1.5% + 20p.
        $this->assertSame(0.08, $payloadArray['donation']['charityFeeVat']);
        $this->assertSame('GB', $payloadArray['donation']['countryCode']);
        $this->assertSame(12, $payloadArray['donation']['donationAmount']);
        $this->assertSame(self::DONATION_UUID, $payloadArray['donation']['donationId']);
        $this->assertSame(0, $payloadArray['donation']['matchReservedAmount']);
        $this->assertSame(1.11, $payloadArray['donation']['tipAmount']);
        $this->assertSame('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertSame('123CAmPAIGNID12345', $payloadArray['donation']['projectId']);
    }

    /**
     * Persist itself failing with a non-retryable exception should mean we give up immediately.
     */
    public function testErrorWhenDbPersistCallFails(): void
    {
        $donation = $this->getTestDonation(true, true, true);

        $app = $this->getAppWithCommonPersistenceDeps(
            donationPersisted: false,
            donationPushed: false,
            donationMatched: false,
            donation: $donation,
            skipEmExpectations: true,
        );

        $this->entityManagerProphecy->isOpen()->willReturn(true);
        $this->entityManagerProphecy->persist(Argument::type(Donation::class))
            ->willThrow($this->prophesize(DBALServerException::class)->reveal())
            ->shouldBeCalledOnce(); // DonationService::MAX_RETRY_COUNT
        $this->entityManagerProphecy->flush()->shouldNotBeCalled();

        $this->diContainer()->set(ClockInterface::class, new MockClock($this->now));


        $data = $this->encode($donation);
        $request = $this->createRequest('POST', Identity::TEST_PERSON_NEW_DONATION_ENDPOINT, $data);
        $response = $app->handle($this->addDummyPersonAuth($request));

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(500, $response->getStatusCode());

        /** @var array<string, mixed> $payloadArray */
        $payloadArray = json_decode($payload, true);

        $this->assertSame(['error' => [
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
     * @return App<ContainerInterface|null>
     */
    private function getAppWithCommonPersistenceDeps(
        bool $donationPersisted,
        bool $donationPushed,
        bool $donationMatched,
        Donation $donation,
        bool $skipEmExpectations = false
    ): App {
        $app = $this->getAppInstance();
        $allocatorProphecy = $this->prophesize(Allocator::class);
        $donationRepoProphecy = $this->donationRepoProphecy;
        $this->campaignRepositoryProphecy->findOneBy(['salesforceId' => '123CAmPAIGNID12345'])->willReturn($this->campaign);

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
            $allocatorProphecy->allocateMatchFunds($donation)->shouldBeCalledOnce();
        } else {
            $allocatorProphecy->allocateMatchFunds($donation)->shouldNotBeCalled();
        }

        $this->entityManagerProphecy->isOpen()->willReturn(true);
        $this->entityManagerProphecy->beginTransaction()->willReturn(null);
        $this->entityManagerProphecy->commit()->willReturn(null);

        if ($donationPersisted) {
            if (!$skipEmExpectations) {
                if ($donationPushed) {
                    // Persist + flush happens twice. See code by comment "Must persist
                    // before Stripe work to have ID available."
                    $this->entityManagerProphecy->persist($donation)->shouldBeCalledTimes(2);
                    $this->entityManagerProphecy->flush()->shouldBeCalledTimes(2);
                } else {
                    $this->entityManagerProphecy->persist($donation)->shouldBeCalledOnce();
                    $this->entityManagerProphecy->flush()->shouldBeCalledOnce();
                }
            }
        }

        $this->diContainer()->set(Allocator::class, $allocatorProphecy->reveal());
        $this->diContainer()->set(CampaignRepository::class, $this->campaignRepositoryProphecy->reveal());
        $this->diContainer()->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $this->diContainer()->set(RoutableMessageBus::class, $this->messageBusProphecy->reveal());

        return $app; // @phpstan-ignore return.type
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

        $campaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign('123CAmPAIGNID12345'), charity: $charity);
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
            $donation->update(paymentMethodType: PaymentMethodType::Card, giftAid: false);
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

        return $donation;
    }

    /**
     * Withdrawal for exactly £8 for now. In this class, typically a partial match.
     */
    private static function someWithdrawal(Donation $donation): FundingWithdrawal
    {
        return new FundingWithdrawal(self::someCampaignFunding(), $donation, '8.00');
    }

    private static function someCampaignFunding(): CampaignFunding
    {
        return new CampaignFunding(
            fund: new FundEntity('GBP', 'some pledge', null, null, FundType::Pledge),
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
        $customerSession->expires_at = time() + 10;
        $stripeProphecy->createCustomerSession(StripeCustomerId::of(self::PSPCUSTOMERID))
            ->willReturn($customerSession);
    }

    public function setupFakeDonationProvider(Donation $donation): void
    {
        $this->diContainer()->get(DonationService::class)->setFakeDonationProviderForTestUseOnly(fn() => $donation);
    }
}
