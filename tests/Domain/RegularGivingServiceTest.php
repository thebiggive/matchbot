<?php

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DomainException\AccountDetailsMismatch;
use MatchBot\Domain\DomainException\BadCommandException;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\HomeAddressRequired;
use MatchBot\Domain\DomainException\NotFullyMatched;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\MandateStatus;
use MatchBot\Domain\PostCode;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\RegularGivingNotifier;
use MatchBot\Domain\RegularGivingService;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use MatchBot\Tests\TestCase;
use PrinsFrank\Standards\Country\CountryAlpha2;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Stripe\ConfirmationToken;
use Stripe\PaymentIntent;

/**

 */
class RegularGivingServiceTest extends TestCase
{
    /** @var ObjectProphecy<DonationRepository> */
    private ObjectProphecy $donationRepositoryProphecy;

    /** @var ObjectProphecy<DonorAccountRepository> */
    private ObjectProphecy $donorAccountRepositoryProphecy;

    /** @var ObjectProphecy<CampaignRepository> */
    private ObjectProphecy $campaignRepositoryProphecy;
    private DonorAccount $donorAccount;
    private PersonId $personId;

    /** @var Salesforce18Id<Campaign> */
    private Salesforce18Id $campaignId;

    /** @var ObjectProphecy<RegularGivingNotifier> */
    private ObjectProphecy $regularGivingNotifierProphecy;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManagerProphecy;


    /** @var ObjectProphecy<DonationService> */
    private ObjectProphecy $donationServiceProphecy;

    /** @var ObjectProphecy<Stripe> */
    private ObjectProphecy $stripeProphecy;

    /** @var ObjectProphecy<RegularGivingMandateRepository> */
    private ObjectProphecy $regularGivingMandateRepositoryProphecy;

    private CampaignFunding $campaignFunding;

    /** @var list<Donation> */
    private array $donations;


    #[\Override]
    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $this->donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);
        $this->campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);

        $this->donorAccount = $this->prepareDonorAccount();

        $this->campaignId = Salesforce18Id::ofCampaign('campaignId12345678');
        $this->personId = PersonId::of('d38667b2-69db-11ef-8885-3f5bcdfd1960');

        $this->donorAccountRepositoryProphecy->findByPersonId($this->personId)
            ->willReturn($this->donorAccount);
        $this->campaignRepositoryProphecy->findOneBySalesforceId($this->campaignId)
            ->willReturn(TestCase::someCampaign(isRegularGiving: true));
        $this->regularGivingNotifierProphecy = $this->prophesize(RegularGivingNotifier::class);
        $this->donationServiceProphecy = $this->prophesize(DonationService::class);
        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $this->campaignFunding = $this->createStub(CampaignFunding::class);

        $testCase = $this;
        $this->donationServiceProphecy->enrollNewDonation(Argument::type(Donation::class), true, false)
            ->will(/**
             * @param Donation[] $args
             */
                function ($args) use ($testCase) {
                    $withdrawal = new FundingWithdrawal($testCase->campaignFunding);
                    $withdrawal->setAmount('42.00');
                    $args[0]->addFundingWithdrawal($withdrawal);

                    $testCase->donations[] = $args[0];
                }
            );

        $this->donationServiceProphecy->queryStripeToUpdateDonationStatus(Argument::type(Donation::class))
            ->will(/**
             * @param array<Donation> $args
             */
                fn(array $args) => $args[0]->collectFromStripeCharge(
                    chargeId: 'chargeId',
                    totalPaidFractional: 1,
                    transferId: 'transferid',
                    cardBrand: null,
                    cardCountry: null,
                    originalFeeFractional: '1',
                    chargeCreationTimestamp: 0,
                )
            );
        $this->stripeProphecy = $this->prophesize(Stripe::class);

        $this->regularGivingMandateRepositoryProphecy = $this->prophesize(RegularGivingMandateRepository::class);
        $this->regularGivingMandateRepositoryProphecy->allPendingForDonorAndCampaign(Argument::cetera())->willReturn([]);
    }

    public function testItCreatesRegularGivingMandate(): void
    {
        // arrange
        $this->givenDonorHasRegularGivingPaymentMethod();

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $this->donationServiceProphecy->confirmDonationWithSavedPaymentMethod(Argument::cetera())->shouldBeCalledOnce();

        // act
        $mandate = $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            TestCase::someCampaign(isRegularGiving: true),
            false,
            DayOfMonth::of(20),
            Country::fromEnum(CountryAlpha2::Kiribati),
            billingPostCode: 'KI0107',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true,
        );

        // assert
        $this->assertSame($mandate->getNumberofMatchedDonations(), 3);
        $this->assertCount(3, $this->donations);
        $this->assertSame(DonationStatus::Collected, $this->donations[0]->getDonationStatus());
        $this->assertSame(DonationStatus::PreAuthorized, $this->donations[1]->getDonationStatus());
        $this->assertSame(DonationStatus::PreAuthorized, $this->donations[2]->getDonationStatus());

        $this->assertEquals(
            new \DateTimeImmutable('2024-12-20T06:00:00 GMT'),
            $this->donations[1]->getPreAuthorizationDate()
        );

        $this->assertEquals(
            new \DateTimeImmutable('2025-01-20T06:00:00 GMT'),
            $this->donations[2]->getPreAuthorizationDate()
        );

        $this->assertSame(Country::fromEnum(CountryAlpha2::Kiribati)->alpha2->value, $this->donorAccount->getBillingCountryCode());
        $this->assertSame('KI0107', $this->donorAccount->getBillingPostcode());

        // Pending, and no notification, until Stripe first donation charge succeeded webhook.
        $this->assertSame(MandateStatus::Pending, $mandate->getStatus());
        $this->regularGivingNotifierProphecy->notifyNewMandateCreated(Argument::cetera())->shouldNotBeCalled();
    }

    public function testItPreservesHomeAddressIfNotSuppliedOnMandate(): void
    {
        // arrange
        $this->givenDonorHasRegularGivingPaymentMethod();
        $this->givenDonorHasHomeAddressAndPostcode();

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $this->donationServiceProphecy->confirmDonationWithSavedPaymentMethod(Argument::cetera())->shouldBeCalledOnce();

        // act
        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            TestCase::someCampaign(isRegularGiving: true),
            false,
            DayOfMonth::of(20),
            Country::fromEnum(CountryAlpha2::Kiribati),
            billingPostCode: 'KI0107',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true,
        );

        $this->assertSame('SW1A 1AA', $this->donorAccount->getHomePostcode());
        $this->assertSame('Home Address', $this->donorAccount->getHomeAddressLine1());
    }

    public function testItSavesUpdatedHomeAddressToDonorAccount(): void
    {
        // arrange
        $this->givenDonorHasRegularGivingPaymentMethod();
        $this->givenDonorHasHomeAddressAndPostcode();

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $this->donationServiceProphecy->confirmDonationWithSavedPaymentMethod(Argument::cetera())->shouldBeCalledOnce();

        // act
        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            TestCase::someCampaign(isRegularGiving: true),
            true,
            DayOfMonth::of(20),
            Country::fromEnum(CountryAlpha2::Kiribati),
            billingPostCode: 'KI0107',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: 'New Home Address',
            homePostcode: PostCode::of('SW2B 2BB', false),
            matchDonations: true,
            homeIsOutsideUk: false,
        );

        $this->assertSame('SW2B 2BB', $this->donorAccount->getHomePostcode());
        $this->assertSame('New Home Address', $this->donorAccount->getHomeAddressLine1());
    }

    public function testItRejectsAttemptToCreateGAMandateWithNoHomeAddress(): void
    {
        // arrange
        $this->givenDonorHasRegularGivingPaymentMethod();

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));

        $this->expectException(HomeAddressRequired::class);
        $this->expectExceptionMessage('Home Address is required when gift aid is selected');
        // act
        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            TestCase::someCampaign(isRegularGiving: true),
            true,
            DayOfMonth::of(20),
            Country::fromEnum(CountryAlpha2::Kiribati),
            billingPostCode: 'KI0107',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: '',
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true,
        );
    }

    public function testItSavesPaymentMethodIDToDonorAccount(): void
    {
        // arrange
        $paymentMethodId = StripePaymentMethodId::of("pm_id");
        $chargeId = 'charge_id';
        $confirmationTokenId = StripeConfirmationTokenId::of('ctoken_xyz');
        $paymentIntent = new PaymentIntent('pi_id');
        $paymentIntent->latest_charge = $chargeId;

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));

        $donation = null;

        $this->donationServiceProphecy->confirmOnSessionDonation(
            Argument::type(Donation::class),
            $confirmationTokenId,
            ConfirmationToken::SETUP_FUTURE_USAGE_OFF_SESSION,
        )
            ->shouldBeCalledOnce()
            ->will(/**
             * @param array{0: Donation} $args
             * @return PaymentIntent
             */
                function (array $args) use (&$donation, $paymentIntent) {
                    $donation = $args[0];
                    return $paymentIntent;
                }
            );

        // act
        $regularGivingService->setupNewMandate(
            donor: $this->donorAccount,
            amount: Money::fromPoundsGBP(42),
            campaign: TestCase::someCampaign(isRegularGiving: true),
            giftAid: false,
            dayOfMonth: DayOfMonth::of(20),
            billingCountry: Country::GB(),
            billingPostCode: 'SW1',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: $confirmationTokenId,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true
        );

        assert($donation instanceof Donation);

        $regularGivingService->updatePossibleMandateFromSuccessfulCharge($donation, $paymentMethodId);

        // assert
        $this->assertEquals(
            $paymentMethodId,
            $this->donorAccount->getRegularGivingPaymentMethod()
        );
    }

    public function testItCancelsAllDonationsOneIsNotFullyMatched(): void
    {
        // arrange
        $this->setDonorDetailsInUK();

        $testCase = $this;
        /** @var Donation[] $donations */
        $donations = [];
        $this->donationServiceProphecy->enrollNewDonation(Argument::type(Donation::class), true, false)
            ->will(/**
             * @param Donation[] $args
             */
                function ($args) use ($testCase, &$donations) {
                    $withdrawal = new FundingWithdrawal($testCase->campaignFunding);
                    $withdrawal->setAmount($args[0]->getMandateSequenceNumber()?->number == 3 ? '42.99' : '42.00');
                    $args[0]->addFundingWithdrawal($withdrawal);

                    $donations[] = $args[0];
                }
            );

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));

        $this->donationServiceProphecy->cancel(Argument::type(Donation::class))
            ->shouldBeCalledTimes(3);

        // act
        try {
            $regularGivingService->setupNewMandate(
                $this->donorAccount,
                Money::fromPoundsGBP(42),
                TestCase::someCampaign(isRegularGiving: true),
                giftAid: false,
                dayOfMonth: DayOfMonth::of(20),
                billingCountry: null,
                billingPostCode: null,
                tbgComms: false,
                charityComms: false,
                confirmationTokenId: null,
                homeAddress: null,
                homePostcode: null,
                matchDonations: true,
                homeIsOutsideUk: true,
            );
            $this->fail('Should throw NotFullyMatched');
        } catch (NotFullyMatched $e) {
            // no-op
        }
    }

    public function testCannotMakeAMandateForNonRegularGivingCampaign(): void
    {
        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $campaign = TestCase::someCampaign();

        $this->expectException(WrongCampaignType::class);

        // By default campaign is not a regular giving campaign
        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(50),
            $campaign,
            giftAid: false,
            dayOfMonth: DayOfMonth::of(12),
            billingCountry: null,
            billingPostCode: null,
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true
        );
    }

    public function testCannotMakeMandateWithCountryNotMatchingAccountCountry(): void
    {
        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $campaign = TestCase::someCampaign(isRegularGiving: true);
        $this->setDonorDetailsInUK();

        $this->expectException(AccountDetailsMismatch::class);
        $this->expectExceptionMessage(
            'Mandate billing country Kiribati (code KI) does not match donor account country United_Kingdom (code GB)'
        );

        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            $campaign,
            giftAid: false,
            dayOfMonth: DayOfMonth::of(12),
            billingCountry: Country::fromEnum(CountryAlpha2::Kiribati),
            billingPostCode: null,
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true
        );
    }

    public function testCanMakeMandateWithJustClosedCampaign(): void
    {
        // at end date exactly
        $this->donationServiceProphecy->confirmDonationWithSavedPaymentMethod(Argument::cetera())->shouldBeCalled();
        $this->createRegularGivingMandate(
            campaignEndDate: '2024-11-29T12:00:00 GMT',
            currentDate: '2024-11-29T12:00:00 GMT'
        );
    }

    public function testCanMakeMandateWithRecentlyClosedCampaign(): void
    {
        // campaign ended under half an hour ago
        $this->donationServiceProphecy->confirmDonationWithSavedPaymentMethod(Argument::cetera())->shouldBeCalled();
        $this->createRegularGivingMandate(
            campaignEndDate: '2024-11-29T12:00:00 GMT',
            currentDate: '2024-11-29T12:29:59 GMT'
        );
    }

    public function testCannotMakeMandateWithCampaignClosedHalfHourAgo(): void
    {
        $this->expectException(CampaignNotOpen::class);
        $this->createRegularGivingMandate(
            campaignEndDate: '2024-11-29T12:00:00 GMT',
            currentDate: '2024-11-29T12:30:00 GMT'
        );
    }

    public function testCannotMakeMandateWithCountryNotMatchingAccountBillingPostcodey(): void
    {
        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $campaign = TestCase::someCampaign(isRegularGiving: true);
        $this->setDonorDetailsInUK();

        $this->expectException(AccountDetailsMismatch::class);
        $this->expectExceptionMessage(
            'Mandate billing postcode KI0107 does not match donor account postcode SW11AA'
        );

        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            $campaign,
            giftAid: false,
            dayOfMonth: DayOfMonth::of(12),
            billingCountry: null,
            billingPostCode: 'KI0107',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true
        );
    }

    public function testMakingNextDonationForMandate(): void
    {
        $sut = $this->makeSut(simulatedNow: new \DateTimeImmutable('2024-10-02T06:00:00+0100'));
        $this->setDonorDetailsInUK();
        $mandate = $this->getMandate(2, '2024-09-03T06:00:00 BST', 1);

        $donation = $sut->makeNextDonationForMandate($mandate);

        $this->assertNotNull($donation);
        $this->assertEquals(DonationSequenceNumber::of(2), $donation->getMandateSequenceNumber());
        $this->assertSame(DonationStatus::PreAuthorized, $donation->getDonationStatus());
        $this->assertEquals(
            new \DateTimeImmutable('2024-10-02T06:00:00.000000+0100'),
            $donation->getPreAuthorizationDate()
        );
    }

    /**
     * There's generally no need to create donations authorized only for payment in the future. We can wait
     * until the authorization time is past and then create the donation and pay it a few minutes or hours later
     * than the first opportunity.
     *
     * Creating donations authorized early is problematic because if we don't limit the timespan we'd have too many
     * inchoate donations in the db if we run the script many times, and we want to create them as late as possible
     * so we can use up to date info from the mandate and the donors account. Most importantly info about whether the
     * mandate has been cancelled but also contact details.
     */
    public function testDoesNotMakeDonationAuthorizedForFuturePayment(): void
    {
        $sut = $this->makeSut(simulatedNow: new \DateTimeImmutable('2024-10-02T05:59:59 BST'));

        $this->setDonorDetailsInUK();
        $mandate = $this->getMandate(2, '2024-09-03T06:00:00 BST', 1);

        // next donation will be number 2. Mandate is activated on 2024-09-03 and dayOfMonth is 2 so donation 2 should
        //be authed for 2024-10-02 . So we should not create this donation if the current date is <
        // '2024-10-02T06:00:00.000000 BST'
        $donation = $sut->makeNextDonationForMandate($mandate);

        $this->assertNull($donation);
    }

    public function testDoesNotLeaveDonorAddressChangedIfDonationServiceThrows(): void
    {

        $this->donorAccount->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_x'));
        $this->donorAccount->setHomePostcode(PostCode::of('SW1A 1AA'));
        $this->donorAccount->setHomeAddressLine1('Existing address');

        $regularGivingService = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $this->donationServiceProphecy->enrollNewDonation(Argument::type(Donation::class), true, false)->willThrow(CampaignNotOpen::class);
        $this->donationServiceProphecy->cancel(Argument::type(Donation::class))->shouldBeCalled();

        try {
            $regularGivingService->setupNewMandate(
                $this->donorAccount,
                Money::fromPoundsGBP(42),
                TestCase::someCampaign(isRegularGiving: true),
                false,
                DayOfMonth::of(20),
                Country::fromEnum(CountryAlpha2::Kiribati),
                billingPostCode: 'KI0107',
                tbgComms: false,
                charityComms: false,
                confirmationTokenId: null,
                homeAddress: 'New address that we dont expect to save because the service throws',
                homePostcode: PostCode::of('SW2B 2BB'),
                matchDonations: true,
                homeIsOutsideUk: false,
            );
            $this->fail('expected to throw CampaignNotOpen');
        } catch (CampaignNotOpen $_e) {
            // no-op
        }

        $this->assertSame('SW1A 1AA', $this->donorAccount->getHomePostcode());
        $this->assertSame('Existing address', $this->donorAccount->getHomeAddressLine1());
    }

    public function testCancelsMandateOnBehalfOfDoor(): void
    {
        $now = new \DateTimeImmutable('2024-11-29T05:59:59 GMT');
        $sut = $this->makeSut($now);

        $mandate = $this->getMandate(2, '2024-09-03T06:00:00 BST', 1);

        $this->donationRepositoryProphecy->findPendingAndPreAuthedForMandate($mandate->getUuid())->willReturn([]);
        $sut->cancelMandate($mandate, 'Because I don\'t have any more money to give', MandateCancellationType::DonorRequestedCancellation);

        $this->assertSame(MandateStatus::Cancelled, $mandate->getStatus());
        $this->assertSame('Because I don\'t have any more money to give', $mandate->cancellationReason());
        $this->assertSame(MandateCancellationType::DonorRequestedCancellation, $mandate->cancellationType());
        $this->assertSame($now, $mandate->cancelledAt());
    }

    public function testCancellingMandateCancelsPendingDonations(): void
    {
        $sut = $this->makeSut(new \DateTimeImmutable('2024-11-29T05:59:59 GMT'));
        $mandate = $this->getMandate(2, '2024-09-03T06:00:00 BST', 1);

        $donation = self::someDonation();
        $this->donationRepositoryProphecy->findPendingAndPreAuthedForMandate($mandate->getUuid())->willReturn([$donation]);

        $this->donationServiceProphecy->cancel($donation)->shouldBeCalled();

        $sut->cancelMandate(mandate: $mandate, reason: '', cancellationType: MandateCancellationType::DonorRequestedCancellation);
    }

    public function testItRemovesADonorsRegularGivingPaymentMethod(): void
    {
        $paymentMethodId = StripePaymentMethodId::of('pm_abcd');
        $this->donorAccount->setRegularGivingPaymentMethod($paymentMethodId);

        $this->regularGivingMandateRepositoryProphecy->allActiveMandatesForDonor($this->donorAccount->id())
            ->willReturn([]);

        $this->stripeProphecy->detatchPaymentMethod($paymentMethodId)->shouldBeCalled();

        $this->makeSut()->removeDonorRegularGivingPaymentMethod($this->donorAccount);

        $this->assertNull($this->donorAccount->getRegularGivingPaymentMethod());
    }

    public function testItWontRemoveADonorsRegularGivingPaymentMethodGivenActiveMandate(): void
    {
        $paymentMethodId = StripePaymentMethodId::of('pm_abcd');
        $this->donorAccount->setRegularGivingPaymentMethod($paymentMethodId);

        $this->regularGivingMandateRepositoryProphecy->allActiveMandatesForDonor($this->donorAccount->id())
            ->willReturn([[
                self::someMandate(),
                self::someCharity(name: 'The_charity_you_are_actively_donating_to'),
        ]]);

        $this->stripeProphecy->detatchPaymentMethod($paymentMethodId)->shouldNotBeCalled();

        $this->expectException(BadCommandException::class);
        $this->expectExceptionMessage(
            'You have an active regular giving mandate for The_charity_you_are_actively_donating_to.'
        );
        $this->makeSut()->removeDonorRegularGivingPaymentMethod($this->donorAccount);
    }


    private function makeSut(?\DateTimeImmutable $simulatedNow = null): RegularGivingService
    {
        return new RegularGivingService(
            now: $simulatedNow ?? new \DateTimeImmutable('2025-01-01T00:00:00'),
            donationRepository: $this->donationRepositoryProphecy->reveal(),
            donorAccountRepository: $this->donorAccountRepositoryProphecy->reveal(),
            campaignRepository: $this->campaignRepositoryProphecy->reveal(),
            entityManager: $this->entityManagerProphecy->reveal(),
            donationService: $this->donationServiceProphecy->reveal(),
            log: $this->createStub(LoggerInterface::class),
            regularGivingMandateRepository: $this->regularGivingMandateRepositoryProphecy->reveal(),
            regularGivingNotifier: $this->regularGivingNotifierProphecy->reveal(),
            stripe: $this->stripeProphecy->reveal(),
        );
    }

    private function getMandate(int $dayOfMonth, string $activationDate, int $maxSequenceNumber): RegularGivingMandate
    {
        $mandateId = 53;
        $mandate = new RegularGivingMandate(
            $this->personId,
            Money::fromPoundsGBP(1),
            $this->campaignId,
            Salesforce18Id::ofCharity('charityId123456789'),
            false,
            DayOfMonth::of($dayOfMonth),
        );
        $mandate->setId($mandateId);

        $mandate->activate(new \DateTimeImmutable($activationDate));

        $this->donationRepositoryProphecy->maxSequenceNumberForMandate($mandateId)
            ->willReturn(DonationSequenceNumber::of($maxSequenceNumber));
        return $mandate;
    }

    private function prepareDonorAccount(?StripePaymentMethodId $stripePaymentMethodId = null): DonorAccount
    {
        $donorId = self::randomPersonId();
        $donorAccount = new DonorAccount(
            $donorId,
            EmailAddress::of('email@example.com'),
            DonorName::of('First', 'Last'),
            StripeCustomerId::of('cus_x')
        );

        if ($stripePaymentMethodId) {
            $donorAccount->setRegularGivingPaymentMethod($stripePaymentMethodId);
        }

        $donorAccount->setHomeAddressLine1('Home address');
        $this->donorAccountRepositoryProphecy->findByPersonId($donorId)
            ->willReturn($donorAccount);

        $this->donorAccount = $donorAccount;

        return $donorAccount;
    }

    private function setDonorDetailsInUK(): void
    {
        $this->donorAccount->setBillingCountry(Country::GB());
        $this->donorAccount->setBillingPostcode('SW11AA');
    }

    private function givenDonorHasRegularGivingPaymentMethod(): void
    {
        $this->donorAccount->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_x'));
    }

    private function givenDonorHasHomeAddressAndPostcode(): void
    {
        $this->donorAccount->setHomePostcode(PostCode::of('SW1A 1AA'));
        $this->donorAccount->setHomeAddressLine1('Home Address');
    }

    private function createRegularGivingMandate(string $campaignEndDate, string $currentDate): void
    {
        $regularGivingService = $this->makeSut(new \DateTimeImmutable($currentDate));
        $this->givenDonorHasRegularGivingPaymentMethod();

        $campaign = TestCase::someCampaign(isRegularGiving: true);
        $campaign->setEndDate(new \DateTimeImmutable($campaignEndDate));

        $regularGivingService->setupNewMandate(
            $this->donorAccount,
            Money::fromPoundsGBP(42),
            $campaign,
            false,
            DayOfMonth::of(20),
            Country::fromEnum(CountryAlpha2::Kiribati),
            billingPostCode: 'KI0107',
            tbgComms: false,
            charityComms: false,
            confirmationTokenId: null,
            homeAddress: null,
            homePostcode: null,
            matchDonations: true,
            homeIsOutsideUk: true,
        );
    }
}
