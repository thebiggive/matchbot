<?php

declare(strict_types=1);

namespace MatchBot\Tests;

use DI\Container;
use DI\ContainerBuilder;
use Exception;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\ApplicationStatus;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFamily;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityResponseToOffer;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\IntegrationTests\IntegrationTest;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Random\Randomizer;
use Redis;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;
use Slim\Psr7\Uri;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use MatchBot\Client;

/**
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 */
class TestCase extends PHPUnitTestCase
{
    use ProphecyTrait;

    /** @var SFCampaignApiResponse  */
    public const array CAMPAIGN_FROM_SALESFORCE = [
        'id' => 'a05xxxxxxxxxxxxxxx',
        'isMetaCampaign' => false,
        'aims' => [0 => 'First Aim'],
        'ready' => true,
        'title' => 'Save Matchbot',
        'video' => null,
        'hidden' => false,
        'quotes' => [],
        'status' => 'Active',
        'target' => 100.0,
        'endDate' => '2095-08-01T00:00:00.000Z',
        'logoUri' => null,
        'problem' => 'Matchbot is threatened!',
        'summary' => 'We can save matchbot',
        'updates' => [],
        'solution' => 'do the saving',
        'bannerUri' => null,
        'countries' => [0 => 'United Kingdom',],
        'isMatched' => true,
        'parentRef' => null,
        'startDate' => '2015-08-01T00:00:00.000Z',
        'categories' => ['Education/Training/Employment', 'Religious'],
        'championRef' => null,
        'amountRaised' => 0.0,
        'championName' => '',
        'currencyCode' => 'GBP',
        'parentTarget' => null,
        'beneficiaries' => ['Animals'],
        'budgetDetails' => [
            ['amount' => 23.0, 'description' => 'Improve the code'],
            ['amount' => 23.0, 'description' => 'Improve the code'], // duplicate item to allow testing de-dupe code.
            ['amount' => 27.0, 'description' => 'Invent a new programing paradigm'],
        ],
        'campaignCount' => null,
        'donationCount' => 0,
        'impactSummary' => null,
        'impactReporting' => null,
        'isRegularGiving' => false,
        'isEmergencyIMF' => false,
        'slug' => null,
        'campaignFamily' => 'summerGive',
        'matchFundsTotal' => 50.0,
        'thankYouMessage' => 'Thank you for helping us save matchbot! We will be able to match twice as many bots now!',
        'usesSharedFunds' => false,
        'alternativeFundUse' => null,
        'parentAmountRaised' => null,
        'additionalImages' => [],
        'additionalImageUris' => [],
        'matchFundsRemaining' => 50.0,
        'parentDonationCount' => null,
        'surplusDonationInfo' => '',
        'parentUsesSharedFunds' => false,
        'championOptInStatement' => '',
        'totalAdjustment' => null,
        'parentMatchFundsRemaining' => null,
        'regularGivingCollectionEnd' => null,
        'pinPosition' => null,
        'championPagePinPosition' => null,
        'relatedApplicationStatus' => null,
        'relatedApplicationCharityResponseToOffer' => null,
        'charity' => [
            'id' => 'xxxxxxxxxxxxxxxxxx',
            'name' => 'Society for the advancement of bots and matches',
            'logoUri' => null,
            'twitter' => null,
            'website' => 'https://society-for-the-advancement-of-bots-and-matches.localhost',
            'facebook' => 'https://www.facebook.com/botsAndMatches',
            'linkedin' => 'https://www.linkedin.com/company/botsAndMatches',
            'instagram' => 'https://www.instagram.com/botsAndMatches',
            'phoneNumber' => null,
            'emailAddress' => 'bots-and-matches@example.com',
            'optInStatement' => null,
            'regulatorNumber' => '1000000',
            'regulatorRegion' => 'England and Wales',
            'stripeAccountId' => 'acc_123456',
            'hmrcReferenceNumber' => null,
            'giftAidOnboardingStatus' => 'Invited to Onboard',
        ]
    ];


    /** @var SFCampaignApiResponse  */
    public const array META_CAMPAIGN_FROM_SALESFORCE = [
        'id' => 'a05xxxxxxxxxxxxxxx',
        'isMetaCampaign' => true,
        'ready' => true,
        'title' => 'This is a meta campaign',
        'video' => null,
        'hidden' => false,
        'quotes' => [],
        'status' => 'Active',
        'target' => 100.0,
        'endDate' => '2095-08-01T00:00:00.000Z',
        'logoUri' => null,
        'problem' => '',
        'summary' => '',
        'updates' => [],
        'solution' => 'do the saving',
        'bannerUri' => null,
        'countries' => [0 => 'United Kingdom',],
        'isMatched' => true,
        'parentRef' => null,
        'startDate' => '2015-08-01T00:00:00.000Z',
        'categories' => ['Education/Training/Employment', 'Religious'],
        'championRef' => null,
        'amountRaised' => 0.0,
        'championName' => '',
        'currencyCode' => 'GBP',
        'campaignCount' => null,
        'donationCount' => 0,
        'impactSummary' => null,
        'impactReporting' => null,
        'isRegularGiving' => false,
        'isEmergencyIMF' => false,
        'slug' => 'some-slug',
        'campaignFamily' => 'summerGive',
        'matchFundsTotal' => 50.0,
        'thankYouMessage' => 'Thank you for helping us save matchbot! We will be able to match twice as many bots now!',
        'usesSharedFunds' => false,
        'alternativeFundUse' => null,
        'parentAmountRaised' => null,
        'additionalImages' => [],
        'additionalImageUris' => [],
        'matchFundsRemaining' => 50.0,
        'parentDonationCount' => null,
        'surplusDonationInfo' => '',
        'parentUsesSharedFunds' => false,
        'championOptInStatement' => '',
        'parentMatchFundsRemaining' => null,
        'regularGivingCollectionEnd' => null,
        'totalAdjustment' => 0.0,
        'charity' => null,
        'aims' => [],
        'budgetDetails' => [],
        'beneficiaries' => [],
        'parentTarget' => null,
    ];

    /**
     * @var array<0|1, ?App<ContainerInterface|null>> array of app instances with and without real redis. Each one may be
     *                       initialised up to once per test.
     */
    private array $appInstance = [0 => null, 1 => null];

    public static function someMandate(): RegularGivingMandate
    {
        return new RegularGivingMandate(
            PersonId::of(Uuid::MAX),
            Money::fromPoundsGBP(1),
            Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            Salesforce18Id::ofCharity('xxxxxxxxxxxxxxxxxx'),
            false,
            DayOfMonth::of(1),
        );
    }

    public static function randomString(): string
    {
        return (new Randomizer())->getBytesFromString('abcdef01234567890', 18);
    }

    public function getContainer(): ContainerInterface
    {
        $container = $this->getAppInstance()->getContainer();
        \assert($container !== null);

        return $container;
    }

    /**
     * @return App<ContainerInterface|null>
     * @throws Exception
     */
    protected function getAppInstance(bool $withRealRedis = false): App
    {
        $memoizedInstance = $this->appInstance[(int)$withRealRedis];
        if ($memoizedInstance) {
            // not sure what's wrong with return type
            return $memoizedInstance; // @phpstan-ignore return.type
        }

        // Instantiate PHP-DI ContainerBuilder
        $containerBuilder = new ContainerBuilder();

        // Container intentionally not compiled for tests.

        // Set up dependencies
        $dependencies = require __DIR__ . '/../app/dependencies.php';
        $dependencies($containerBuilder);

        // Set up repositories
        $repositories = require __DIR__ . '/../app/repositories.php';
        $repositories($containerBuilder);

        // Build PHP-DI Container instance
        $container = $containerBuilder->build();

        $container->set(
            'donation-creation-rate-limiter-factory',
            new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage())
        );

        if (!$withRealRedis) {
            // For unit tests, we need to stub out Redis so that rate limiting middleware doesn't
            // crash trying to actually connect to REDIS_HOST "dummy-redis-hostname".
            $redisProphecy = $this->prophesize(Redis::class);
            $redisProphecy->isConnected()->willReturn(true);
            $redisProphecy->mget(Argument::type('array'))->willReturn([]);
            // symfony/cache Redis adapter apparently does something around prepping value-setting
            // through a fancy pipeline() and calls this.
            $redisProphecy->multi(Argument::any())->willReturn(true);
            $redisProphecy
                ->setex(Argument::type('string'), 3600, Argument::type('string'))
                ->willReturn(true);
            $redisProphecy->exec()->willReturn([]); // Commits the multi() operation.
            $container->set(Redis::class, $redisProphecy->reveal());
        }

        // By default, tests don't get a real logger.
        $container->set(LoggerInterface::class, new NullLogger());

        $container->set(ClockInterface::class, new class implements ClockInterface
        {
            #[\Override] public function sleep(float|int $seconds): never
            {
                throw new \Exception("Please provide fake clock for your test");
            }
            #[\Override] public function now(): \DateTimeImmutable
            {
                // This always throws but I'm using an if condition to hide that from PhpStorm. Otherwise
                // PhpStorm infers the return type as `never`, finds usages of the Interface in our prod code
                // and somehow assumes they're calling this anon class and complains about dead code.
                if (time() !== 1) {
                    throw new \Exception("Please provide fake clock for your test");
                }

                return new \DateTimeImmutable();
            }
            #[\Override] public function withTimeZone(\DateTimeZone|string $timezone): never
            {
                throw new \Exception("Please provide fake clock for your test");
            }
        });

        // Instantiate the app
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Register routes
        $routes = require __DIR__ . '/../app/routes.php';
        $routes($app);

        $app->addRoutingMiddleware();
        // not sure what's wrong with assignment here
        $this->appInstance[(int) $withRealRedis] = $app; // @phpstan-ignore assign.propertyType

        // not sure what's wrong with return type.
        return $app; // @phpstan-ignore return.type
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $bodyString
     * @param array<string, string> $headers
     * @param array<string, string> $serverParams
     * @param array<string, string> $cookies
     * @return Request
     */
    public static function createRequest(
        string $method,
        string $path,
        string $bodyString = '',
        array $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-Forwarded-For' => '1.2.3.4', // Simulate ALB in unit tests by default.
        ],
        array $serverParams = [],
        array $cookies = []
    ): Request {
        $uri = new Uri('', '', 80, $path);
        $handle = fopen('php://temp', 'w+');
        \assert($handle !== false);

        if ($bodyString === '') {
            $stream = (new StreamFactory())->createStreamFromResource($handle);
        } else {
            $stream = (new StreamFactory())->createStream($bodyString);
        }

        $h = new Headers();
        foreach ($headers as $name => $value) {
            $h->addHeader($name, $value);
        }

        return new SlimRequest($method, $uri, $h, $cookies, $serverParams, $stream);
    }

    public static function getMinimalCampaign(): Campaign
    {
        $charity = self::someCharity();
        $charity->setTbgClaimingGiftAid(false);
        $campaign = TestCase::someCampaign(
            charity: $charity
        );
        $campaign->setIsMatched(false);

        return $campaign;
    }

    /**
     * Returns some random charity - use if you don't care about the details or will replace them with setters later.
     * Introduced to replace many old calls to instantiate Charity with zero arguments.
     *
     * @param Salesforce18Id<Charity>|null $salesforceId
     */
    public static function someCharity(
        ?string $stripeAccountId = null,
        ?Salesforce18Id $salesforceId = null,
        string $name = 'Charity Name',
        ?string $phoneNumber = null,
        ?EmailAddress $emailAddress = null,
    ): Charity {
        return new Charity(
            salesforceId: $salesforceId->value ?? ('123CharityId' . self::randomHex(3)),
            charityName: $name,
            stripeAccountId: $stripeAccountId ?? "stripe-account-id-" . self::randomHex(),
            hmrcReferenceNumber: 'H' . self::randomHex(3),
            giftAidOnboardingStatus: 'Onboarded',
            regulator: 'CCEW',
            regulatorNumber: 'Reg-no',
            time: new \DateTime('2023-10-06T18:51:27'),
            websiteUri: 'https://charityname.com',
            logoUri: 'https://some-logo-host/charityname/logo.png',
            phoneNumber: $phoneNumber,
            emailAddress: $emailAddress,
            rawData: self::CAMPAIGN_FROM_SALESFORCE['charity'],
        );
    }

    /**
     * @param ?Salesforce18Id<Campaign> $sfId
     * @param 'Active'|'Expired'|'Preview' $status
     */
    public static function someCampaign(
        ?string $stripeAccountId = null,
        ?Salesforce18Id $sfId = null,
        ?Charity $charity = null,
        bool $isRegularGiving = false,
        ?\DateTimeImmutable $regularGivingCollectionEnd = null,
        string $thankYouMessage = null,
        MetaCampaignSlug $metaCampaignSlug = null,
        bool $isMatched = false,
        ?bool $charityRejected = false,
        ?Money $totalFundraisingTarget = null,
        ?Money $amountPledged = null,
        ?Money $totalFundingAllocation = null,
        string $status = 'Active',
    ): Campaign {
        $randomString = (new Randomizer())->getBytesFromString('abcdef', 7);
        $sfId ??= Salesforce18Id::ofCampaign('1CampaignId' . $randomString);

        return new Campaign(
            $sfId,
            metaCampaignSlug: $metaCampaignSlug?->slug,
            charity: $charity ?? self::someCharity(stripeAccountId: $stripeAccountId),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('3000-01-01'),
            isMatched: $isMatched,
            ready: true,
            status: $status,
            name: 'someCampaign',
            summary: 'Some Campaign Summary',
            currencyCode: 'GBP',
            totalFundingAllocation: $totalFundingAllocation ?? Money::zero(),
            amountPledged: $amountPledged ?? Money::zero(),
            isRegularGiving: $isRegularGiving,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: $metaCampaignSlug === null ? null : ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: $charityRejected ? CharityResponseToOffer::Rejected : CharityResponseToOffer::Accepted,
            regularGivingCollectionEnd: $regularGivingCollectionEnd,
            totalFundraisingTarget: $totalFundraisingTarget ?? Money::zero(),
            thankYouMessage: $thankYouMessage,
            rawData: self::CAMPAIGN_FROM_SALESFORCE,
            hidden: false,
        );
    }

    /**
     * @param numeric-string $amount
     * @param numeric-string $tipAmount
     */
    public static function someDonation(
        UuidInterface $uuid = null,
        string $amount = '1',
        string $currencyCode = 'GBP',
        PaymentMethodType $paymentMethodType = PaymentMethodType::Card,
        bool $giftAid = false,
        ?RegularGivingMandate $regularGivingMandate = null,
        ?Campaign $campaign = null,
        string $tipAmount = '0',
        ?EmailAddress $emailAddress = null,
        ?DonorName $donorName = null,
        bool $collected = false,
        ?string $transferId = null,
        ?int $mandateSequenceNumber = null,
    ): Donation {

        $donation = new Donation(
            amount: $amount,
            currencyCode: $currencyCode,
            paymentMethodType: $paymentMethodType,
            campaign: $campaign ?? self::someCampaign(),
            charityComms: null,
            championComms: null,
            pspCustomerId: null,
            optInTbgEmail: null,
            donorName: $donorName,
            emailAddress: $emailAddress,
            countryCode: null,
            tipAmount: $tipAmount,
            mandate: $regularGivingMandate,
            mandateSequenceNumber: is_int($mandateSequenceNumber) ? DonationSequenceNumber::of($mandateSequenceNumber) : null,
            giftAid: $giftAid,
            tipGiftAid: null,
            homeAddress: null,
            homePostcode: null,
            billingPostcode: null,
            donorId: PersonId::of(Uuid::NIL),
        );

        $donation->setUuid($uuid ?? Uuid::uuid4());

        if ($collected) {
            self::collectDonation(
                $donation,
                $transferId,
                (int) (100.0 * ((float) $amount + (float) $tipAmount))
            );
        }

        return $donation;
    }

    protected static function collectDonation(Donation $donationResponse, ?string $transferId = null, int $totalPaidFractional = 100): void
    {
        $donationResponse->collectFromStripeCharge(
            chargeId: 'testchargeid_' . self::randomString(),
            totalPaidFractional: $totalPaidFractional,
            transferId: $transferId ?? 'test_transfer_id_' . self::randomHex(),
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: (int)(new \DateTimeImmutable('1970-01-01'))->format('U'),
        );
    }

    public static function someUpsertedMessage(): DonationUpserted
    {
        $donation = self::someDonation();
        $donation->setTransactionId('pi_1234');

        return DonationUpserted::fromDonation($donation);
    }

    /**
     * @param positive-int $num_bytes
     */
    private static function randomHex(int $num_bytes=8): string
    {
        return bin2hex(random_bytes($num_bytes));
    }

    public static function randomPersonId(): PersonId
    {
        return PersonId::of(Uuid::uuid4()->toString());
    }

    public function diContainer(): Container
    {
        $container = $this->getAppInstance()->getContainer();

        \assert($container instanceof Container);

        return $container;
    }

    public static function getSalesforceAuthValue(string $body): string
    {
        $salesforceSecretKey = getenv('SALESFORCE_SECRET_KEY');
        \assert(is_string($salesforceSecretKey));

        return hash_hmac('sha256', $body, $salesforceSecretKey);
    }

    public static function someMetaCampaign(bool $isRegularGiving, bool $isEmergencyIMF, ?MetaCampaignSlug $slug = null, ?\DateTimeImmutable $startDate = null): MetaCampaign
    {
        return new MetaCampaign(
            slug: $slug ?? MetaCampaignSlug::of('not-relevant-' . TestCase::randomHex()),
            salesforceId: IntegrationTest::randomSalesForce18Id(MetaCampaign::class),
            title: 'not relevant ' . TestCase::randomHex(),
            currency: Currency::GBP,
            status: 'Active',
            masterCampaignStatus: MetaCampaign::STATUS_VIEW_CAMPAIGN,
            hidden: false,
            summary: 'not relevant',
            bannerURI: null,
            startDate: $startDate ?? new \DateTimeImmutable('1970'),
            endDate: new \DateTimeImmutable('1970'),
            isRegularGiving: $isRegularGiving,
            isEmergencyIMF: $isEmergencyIMF,
            totalAdjustment: Money::zero(),
            campaignFamily: CampaignFamily::artsforImpact,
        );
    }
}
