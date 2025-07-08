<?php

namespace MatchBot\Tests\Domain;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Driver\PDO\Exception as PDOException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Notifier\StripeChatterInterface;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Country;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestLogger;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\ConfirmationToken;
use Stripe\Exception\PermissionException;
use Stripe\PaymentIntent;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class DonationServiceTest extends TestCase
{
    private const string CUSTOMER_ID = 'cus_CUSTOMERID';
    public const string CAMPAIGN_ID = 'PROjECTIdXXXXxXXXX';
    private DonationService $sut;

    /** @var ObjectProphecy<Stripe> */
    private ObjectProphecy $stripeProphecy;

    /** @var ObjectProphecy<DonationRepository> */
    private ObjectProphecy $donationRepoProphecy;

    /** @var ObjectProphecy<StripeChatterInterface> */
    private ObjectProphecy $chatterProphecy;

    /** @var ObjectProphecy<DonorAccountRepository> */
    private ObjectProphecy $donorAccountRepoProphecy;

    /**
     * @var ObjectProphecy<EntityManagerInterface>
     */
    private ObjectProphecy $entityManagerProphecy;

    #[\Override]
    public function setUp(): void
    {
        $this->donorAccountRepoProphecy = $this->prophesize(DonorAccountRepository::class);
        $this->donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $this->chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $configurationProphecy = $this->prophesize(\Doctrine\ORM\Configuration::class);
        $config = $configurationProphecy->reveal();
        $configurationProphecy->getResultCacheImpl()->willReturn($this->createStub(CacheProvider::class));

        $this->entityManagerProphecy->getConfiguration()->willReturn($config);
    }

    public function testIdentifiesCharityLackingCapabilities(): void
    {
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);

        $this->sut = $this->getDonationService(withAlwaysCrashingEntityManager: false, campaignRepoProphecy: $campaignRepoProphecy);

        $donationCreate = $this->getDonationCreate();
        $donation = $this->getDonation();
        $campaignRepoProphecy->findOneBy(['salesforceId' => self::CAMPAIGN_ID])->willReturn($donation->getCampaign());

        $this->chatterProphecy->send(
            Argument::type(ChatMessage::class),
        )->will(
            /**
         * @param ChatMessage[] $args
         */
            function (array $args) {
                TestCase::assertStringContainsString('[test] Stripe Payment Intent create error', $args[0]->getSubject());
                TestCase::assertStringContainsString('[capabilities list]', $args[0]->getSubject());
                TestCase::assertStringContainsString('Charity Name [STRIPE-ACCOUNT-ID]', $args[0]->getSubject());
            }
        )
            ->shouldBeCalledOnce();

        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willThrow(new PermissionException(DonationService::STRIPE_DESTINATION_ACCOUNT_NEEDS_CAPABILITIES_MESSAGE . " [capabilities list]"));

        $this->expectException(CharityAccountLacksNeededCapaiblities::class);

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID, PersonId::nil());
    }

    public function testInitialPersistRunsOutOfRetries(): void
    {
        $logger = new TestLogger();

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);

        $this->sut = $this->getDonationService(withAlwaysCrashingEntityManager: true, logger: $logger, campaignRepoProphecy: $campaignRepoProphecy);

        $donationCreate = $this->getDonationCreate();
        $donation = $this->getDonation();
        $campaignRepoProphecy->findOneBy(['salesforceId' => self::CAMPAIGN_ID])
            ->willReturn($donation->getCampaign());

        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willReturn($this->prophesize(\Stripe\PaymentIntent::class)->reveal());

        try {
            $this->sut->createDonation($donationCreate, self::CUSTOMER_ID, PersonId::nil());
            $this->fail('Should have thrown LockWaitTimeoutException');
        } catch (LockWaitTimeoutException) {
            // pass – in app context, final exception will be surfaced as an error to Slack etc.
        }

        $this->assertSame(
            <<<'EOF'
            warning: Error creating donation, will retry: An exception occurred in the driver: EXCEPTION_MESSAGE

            EOF,
            $logger->logString
        );
    }

    public function testRefusesToConfirmPreAuthedDonationForNonActiveMandate(): void
    {
        $mandate = self::someMandate();

        $mandate->activate(new \DateTimeImmutable('2024-09-01'));

        $stripeCustomerId = StripeCustomerId::of('cus_123');
        $donor = new DonorAccount(
            self::randomPersonId(),
            EmailAddress::of('example@email.com'),
            DonorName::of('first', 'last'),
            $stripeCustomerId,
        );
        $donor->setBillingPostcode('SW11AA');
        $donor->setBillingCountry(Country::GB());
        $donor->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_paymentMethodID'));

        $donation = $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(2),
            $donor,
            TestCase::someCampaign(),
        );

        $this->donorAccountRepoProphecy->findByStripeIdOrNull($stripeCustomerId)->willReturn($donor);

        $mandate->cancel(
            reason: '',
            at: new \DateTimeImmutable(),
            type: MandateCancellationType::DonorRequestedCancellation
        );

        $this->expectException(MandateNotActive::class);
        $this->expectExceptionMessage("Not confirming donation as mandate is 'Cancelled', not Active");

        $this->getDonationService()->confirmPreAuthorized($donation);
    }

    /**
     * @param bool $withAlwaysCrashingEntityManager Whether to simulate EM always throwing a *retryable* exception
     * @param ObjectProphecy<CampaignRepository>|null $campaignRepoProphecy
     * @param ObjectProphecy<FundRepository>|null $fundRepoProphecy
     */
    private function getDonationService(
        bool $withAlwaysCrashingEntityManager = false,
        LoggerInterface $logger = null,
        ?ObjectProphecy $campaignRepoProphecy = null,
        ?ObjectProphecy $fundRepoProphecy = null,
    ): DonationService {
        if ($withAlwaysCrashingEntityManager) {
            /**
             * @psalm-suppress InternalMethod
             * @psalm-suppress InternalClass Hard to simulate `final` exception otherwise
             */
            $this->entityManagerProphecy->persist(Argument::type(Donation::class))->willThrow(
                new LockWaitTimeoutException(new PDOException('EXCEPTION_MESSAGE'), null)
            );
        } else {
            $this->entityManagerProphecy->persist(Argument::type(Donation::class))->willReturn(null);
            $this->entityManagerProphecy->flush()->willReturn(null);
        }

        $this->entityManagerProphecy->beginTransaction()->willReturn(null);
        $this->entityManagerProphecy->commit()->willReturn(null);

        $logger = $logger ?? new NullLogger();


        $campaignRepoProphecy ??= $this->prophesize(CampaignRepository::class);
        $fundRepoProphecy ??= $this->prophesize(FundRepository::class);

        return new DonationService(
            allocator: $this->createStub(Allocator::class),
            donationRepository: $this->donationRepoProphecy->reveal(),
            campaignRepository: $campaignRepoProphecy->reveal(),
            logger: $logger,
            entityManager: $this->entityManagerProphecy->reveal(),
            stripe: $this->stripeProphecy->reveal(),
            matchingAdapter: $this->prophesize(Adapter::class)->reveal(),
            chatter: $this->chatterProphecy->reveal(),
            clock: new MockClock(new \DateTimeImmutable('2025-01-01')),
            rateLimiterFactory: new RateLimiterFactory(['id' => 'stub', 'policy' => 'no_limit'], new InMemoryStorage()),
            donorAccountRepository: $this->donorAccountRepoProphecy->reveal(),
            bus: $this->createStub(RoutableMessageBus::class),
            donationNotifier: $this->createStub(DonationNotifier::class),
            fundRepository: $fundRepoProphecy->reveal(),
        );
    }

    private function getDonationCreate(): DonationCreate
    {
        return new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '1',
            projectId: self::CAMPAIGN_ID,
            psp: 'stripe',
            pspCustomerId: self::CUSTOMER_ID
        );
    }

    private function getDonation(): Donation
    {
        return Donation::fromApiModel(
            $this->getDonationCreate(),
            TestCase::someCampaign(stripeAccountId: 'STRIPE-ACCOUNT-ID'),
            PersonId::nil()
        );
    }

    /**
     * Not attempting to cover all possible variations here, just one example of confirming via the
     * \MatchBot\Domain\DonationService::confirmOnSessionDonation . Details of the fee calcuations are
     * tested directly against the Calculator class.
     */
    public function testItConfirmsOnSessionUKVisaDonationChargingAppropriateFee(): void
    {
        $confirmationTokenId = StripeConfirmationTokenId::of('ctoken_xyz');
        $paymentIntentId = 'payment_intent_id';

        $donation = TestCase::someDonation(amount: '15.00');
        $donation->setTransactionId($paymentIntentId);

        $this->stripeProphecy->retrieveConfirmationToken($confirmationTokenId)
            ->will(function () {
                $confirmationToken = new ConfirmationToken();
                /** @psalm-suppress InvalidPropertyAssignmentValue */
                $confirmationToken->payment_method_preview = [
                    'card' => [
                        'brand' => 'visa',
                        'country' => 'gb',
                    ],
                ];
                return $confirmationToken;
            });

        // Gross fee is £0.52 because in the case of a UK visa card the fee is
        // 1.5% * the donation amount, plus 20p, plus 20% vat - and we round the net amount to pence
        // before adding VAT, then round it again after:
        $this->assertSame(
            52.0,
            round(round(15_00.0 * 1.5 / 100.0 + 20.0) * 1.2)
        );

        $this->stripeProphecy->updatePaymentIntent($paymentIntentId, [
            'metadata' => [
                'stripeFeeRechargeGross' => '0.52',
                'stripeFeeRechargeNet' => '0.43',
                'stripeFeeRechargeVat' => '0.09',
            ],
            'application_fee_amount' => '52',
        ])->shouldBeCalledOnce();

        $paymentIntent = new PaymentIntent($paymentIntentId);
        $paymentIntent->status = PaymentIntent::STATUS_SUCCEEDED;
        $paymentIntent->setup_future_usage = PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION;

        $this->stripeProphecy->confirmPaymentIntent($paymentIntentId, [
            'confirmation_token' => $confirmationTokenId->stripeConfirmationTokenId,
        ])
            ->willReturn($paymentIntent)
            ->shouldBeCalledOnce();

        $this->stripeProphecy->retrievePaymentIntent($paymentIntentId)
            ->willReturn($paymentIntent)
            ->shouldBeCalledOnce();

        // act
        $this->getDonationService()->confirmOnSessionDonation(
            $donation,
            $confirmationTokenId,
            ConfirmationToken::SETUP_FUTURE_USAGE_ON_SESSION,
        );

        $this->assertSame('0.52', $donation->getCharityFeeGross());
    }


    public function testBuildFromApiRequestSuccess(): void
    {

        $dummyCampaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign(self::CAMPAIGN_ID));

        $dummyCampaign->setCurrencyCode('USD');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(['salesforceId' => self::CAMPAIGN_ID])
            ->willReturn($dummyCampaign);


        $createPayload = new DonationCreate(
            currencyCode: 'USD',
            donationAmount: '123',
            projectId: self::CAMPAIGN_ID,
            psp: 'stripe',
            pspMethodType: PaymentMethodType::Card,
        );

        $donation = $this->getDonationService(campaignRepoProphecy: $campaignRepoProphecy)
            ->buildFromAPIRequest($createPayload, PersonId::nil());

        $this->assertSame('USD', $donation->currency()->isoCode());
        $this->assertSame('123', $donation->getAmount());
        $this->assertSame(12_300, $donation->getAmountFractionalIncTip());
    }


    public function testItPullsCampaignFromSFIfNotInRepo(): void
    {
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $fundRepositoryProphecy = $this->prophesize(FundRepository::class);
        $this->entityManagerProphecy->flush()->shouldBeCalled();

        $dummyCampaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign(self::CAMPAIGN_ID));
        $dummyCampaign->setCurrencyCode('GBP');


        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(['salesforceId' => self::CAMPAIGN_ID])
            ->willReturn(null);
        $campaignRepoProphecy->pullNewFromSf(Salesforce18Id::ofCampaign(self::CAMPAIGN_ID))
            ->willReturn($dummyCampaign);

        $fundRepositoryProphecy->pullForCampaign(Argument::type(Campaign::class), Argument::type(\DateTimeImmutable::class))->shouldBeCalled();

        $createPayload = new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '123',
            projectId: self::CAMPAIGN_ID,
            psp: 'stripe',
            pspMethodType: PaymentMethodType::Card,
        );

        $donation = $this->getDonationService(
            campaignRepoProphecy: $campaignRepoProphecy,
            fundRepoProphecy: $fundRepositoryProphecy
        )
            ->buildFromAPIRequest(
                $createPayload,
                PersonId::nil(),
            );

        $this->assertSame(self::CAMPAIGN_ID, $donation->getCampaign()->getSalesforceId());
    }

    public function testBuildFromApiRequestWithCurrencyMismatch(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Currency CAD is invalid for campaign');

        $dummyCampaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign(self::CAMPAIGN_ID));

        $dummyCampaign->setCurrencyCode('USD');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(Argument::type('array'))
            ->willReturn($dummyCampaign)
            ->shouldBeCalledOnce();

        $createPayload = new DonationCreate(
            currencyCode: 'CAD',
            donationAmount: '144.0',
            projectId: self::CAMPAIGN_ID,
            psp: 'stripe',
            pspMethodType: PaymentMethodType::Card,
        );

        $this->getDonationService(campaignRepoProphecy: $campaignRepoProphecy)
            ->buildFromAPIRequest($createPayload, PersonId::nil());
    }
}
