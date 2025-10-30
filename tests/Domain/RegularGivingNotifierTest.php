<?php

namespace MatchBot\Tests\Domain;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching\Adapter as MatchingAdapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Client\Mailer;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DomainException\PaymentIntentNotSucceeded;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MandateStatus;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Fund;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingNotifier;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use MatchBot\Tests\TestCase;
use PharIo\Manifest\Email;
use PhpParser\Node\Arg;
use Prophecy\Argument;
use Prophecy\Argument\Token\TypeToken;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\StripeObject;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class RegularGivingNotifierTest extends TestCase
{
    /** @var ObjectProphecy<Mailer> */
    private ObjectProphecy $mailerProphecy;

    /** @var PersonId  */
    private PersonId $personId;

    /** @var ObjectProphecy<Stripe>  */
    private ObjectProphecy $stripeClientProphecy;
    private MockClock $clock;
    private RegularGivingNotifier $sut;

    /** @var ObjectProphecy<DonorAccountRepository>  */
    private ObjectProphecy $donorAccountRepositoryProphecy;
    private DonorAccount $donor;

    public function testItNotifiesOfNewDonationMandate(): void
    {
        $donor = $this->givenADonor();
        list($campaign, $mandate, $firstDonation, $clock) = $this->andGivenAnActivatedMandate($this->personId, $donor);

        $this->thenThisRequestShouldBeSentToMailer(EmailMessage::donorMandateConfirmation(
            EmailAddress::of("donor@example.com"),
            [
                "donorName" => "Jenny Generous",
                "charityName" => "Charity Name",
                "campaignName" => "someCampaign",
                "charityNumber" => "Reg-no",
                "campaignThankYouMessage" => 'Thank you for setting up your regular donation to us!',
                "signupDate" => "1 December 2024, 00:00 GMT",
                "schedule" => "Monthly on day #12",
                "nextPaymentDate" => "12 December 2024",
                "amount" => "£64.00",
                "giftAidValue" => "£16.00",
                "totalIncGiftAid" => "£80.00",
                "totalCharged" => "£64.00",
                "firstDonation" => [
                    // mostly same keys as used on the donorDonationSuccess email currently sent via Salesforce.
                    'currencyCode' => 'GBP',
                    'donationAmount' => 64,
                    'donationDatetime' => '2025-01-01T09:00:00+00:00',
                    'charityName' => 'Charity Name',
                    'matchedAmount' => 64,
                    'transactionId' => '[PSP Transaction ID]',
                    'statementReference' => 'Big Give Charity Name',
                    'giftAidAmountClaimed' => 16.00,
                    'totalCharityValueAmount' => 144.00,
                ]
            ]
        ));

        $this->whenWeNotifyThemThatTheMandateWasCreated($clock, $mandate, $donor, $campaign, $firstDonation);
    }

    public function testItNotifiesOfChargeFailure(): void
    {
        $donor = $this->givenADonor();
        list($_campaign, $_mandate, $_firstDonation, $_clock, $secondDonation) = $this->andGivenAnActivatedMandate($this->personId, $donor);

        $this->thenThisRequestShouldBeSentToMailer(Argument::type(EmailMessage::class));

        $this->thenThisRequestShouldBeSentToMailer(EmailMessage::donorRegularDonationFailed($donor->emailAddress, [
            'originalDonationPaymentDate' => '12 December 2024',
            'collectionAttemptTime' => '15 December 2024, 00:00 GMT',
            'charityName' => 'Charity Name',
            'donorName' => 'Jenny Generous',
            'amount' => '£64.00',
        ]));

        $this->whenAPreauthorizedDonationsChargeFails($secondDonation);
    }

    public function testItCancelsMandateWhenChargeFailsAfterAWeek(): void
    {
        $donor = $this->givenADonor();
        list($_campaign, $mandate, $_firstDonation, $_clock, $secondDonation) = $this->andGivenAnActivatedMandate($this->personId, $donor);

        $this->thenThisRequestShouldBeSentToMailer(Argument::type(EmailMessage::class));

        $this->thenThisRequestShouldBeSentToMailer(EmailMessage::donorRegularDonationFailed($donor->emailAddress, [
            'originalDonationPaymentDate' => '12 December 2024',
            'collectionAttemptTime' => '22 December 2024, 00:00 GMT',
            'charityName' => 'Charity Name',
            'donorName' => 'Jenny Generous',
            'amount' => '£64.00',
        ]));

        $this->whenAWeekPasses();

        $this->whenAPreauthorizedDonationsChargeFails($secondDonation);

        $this->thenTheMandateShouldBeCancelled($mandate);
    }

    private function markDonationCollected(Donation $firstDonation, \DateTimeImmutable $collectionDate): void
    {
        $firstDonation->collectFromStripeCharge(
            'chargeID',
            99,
            'transfer-id',
            CardBrand::amex,
            Country::fromAlpha2('GB'),
            '99',
            $collectionDate->getTimestamp(),
        );
    }

    /**
     * Using reflection to add funding withdrawals to donation. In prod code we rely on the ORM to link
     * Funding withdrawals to donations.
     *
     * @param numeric-string $amount
     */
    private function addFundingWithdrawal(Donation $donation, string $amount): void
    {
        $withdrawal = new FundingWithdrawal(
            new CampaignFunding(
                new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
                '100',
                '100',
            ),
            $donation,
            $amount,
        );

        $reflectionClass = new \ReflectionClass($donation);
        $property = $reflectionClass->getProperty('fundingWithdrawals');

        /** @var Collection<int, FundingWithdrawal> $fundingWithdrawals */
        $fundingWithdrawals = $property->getValue($donation);
        $fundingWithdrawals->add($withdrawal);
    }

    private function givenADonor(): DonorAccount
    {
        $this->donor = new DonorAccount(
            uuid: $this->personId,
            emailAddress: EmailAddress::of('donor@example.com'),
            donorName: DonorName::of('Jenny', 'Generous'),
            stripeCustomerId: StripeCustomerId::of('cus_anyid'),
        );
        $donor = $this->donor;
        $donor->setHomeAddressLine1('pretty how town');
        $donor->setBillingCountry(Country::GB());
        $donor->setBillingPostcode('SW1A 1AA');
        $donor->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_abc'));
        return $donor;
    }

    /**
     * @return list{Campaign, RegularGivingMandate, Donation, ClockInterface, Donation}
     */
    private function andGivenAnActivatedMandate(PersonId $personId, DonorAccount $donor): array
    {
        $campaign = TestCase::someCampaign(thankYouMessage: 'Thank you for setting up your regular donation to us!');

        $mandate = new RegularGivingMandate(
            donorId: $personId,
            donationAmount: Money::fromPoundsGBP(64),
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: Salesforce18Id::ofCharity($campaign->getCharity()->getSalesforceId()),
            giftAid: true,
            dayOfMonth: DayOfMonth::of(12),
        );

        $firstDonation = new Donation(
            amount: '64',
            currencyCode: 'GBP',
            paymentMethodType: PaymentMethodType::Card,
            campaign: $campaign,
            charityComms: false,
            championComms: false,
            pspCustomerId: $donor->stripeCustomerId->stripeCustomerId,
            optInTbgEmail: false,
            donorName: $donor->donorName,
            emailAddress: $donor->emailAddress,
            countryCode: $donor->getBillingCountryCode(),
            tipAmount: '0',
            mandate: $mandate,
            mandateSequenceNumber: DonationSequenceNumber::of(1),
            giftAid: true,
            tipGiftAid: null,
            homeAddress: null,
            homePostcode: null,
            billingPostcode: null,
            donorId: $donor->id(),
        );
        $firstDonation->setTransactionId('[PSP Transaction ID]');
        $this->addFundingWithdrawal($firstDonation, '64');

        $this->markDonationCollected($firstDonation, new \DateTimeImmutable('2025-01-01 09:00:00'));

        $clock = new MockClock('2024-12-01');
        $mandate->activate($clock->now());

        $secondDonation = $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(2),
            $donor,
            $campaign,
        );

        $secondDonation->setTransactionId('transaction_id');

        return [$campaign, $mandate, $firstDonation, $clock, $secondDonation];
    }

    /**
     * @param EmailMessage|TypeToken $sendEmailCommand
     */
    private function thenThisRequestShouldBeSentToMailer(EmailMessage|TypeToken $sendEmailCommand): void
    {
        $this->mailerProphecy->send(Argument::any())
            ->shouldBeCalledOnce()
            ->will(fn(array $args) => TestCase::assertEqualsCanonicalizing($args[0], $sendEmailCommand));
    }

    private function whenWeNotifyThemThatTheMandateWasCreated(
        ClockInterface $clock,
        RegularGivingMandate $mandate,
        DonorAccount $donor,
        Campaign $campaign,
        Donation $firstDonation
    ): void {
        //act
        $this->sut->notifyNewMandateCreated($mandate, $donor, $campaign, $firstDonation);
    }

    public function thenTheMandateShouldBeCancelled(RegularGivingMandate $mandate): void
    {
        $this->assertSame($mandate->getStatus(), MandateStatus::Cancelled);
    }

    /**
     * @return void
     * @throws \DateMalformedStringException
     */
    public function whenAWeekPasses(): void
    {
        $this->clock->modify("+1 week");
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->personId = self::randomPersonId();

        $this->clock = new MockClock('2024-12-01');
        $this->mailerProphecy = $this->prophesize(Mailer::class);
        $this->givenADonor();

        $this->donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);

        $this->donorAccountRepositoryProphecy->findByStripeIdOrNull(Argument::any())->willReturn($this->donor);
        $this->donorAccountRepositoryProphecy->findByPersonId(Argument::any())->willReturn($this->donor);


        $this->sut =  new RegularGivingNotifier(
            $this->mailerProphecy->reveal(),
            $this->donorAccountRepositoryProphecy->reveal(),
            $this->clock,
        );

        $this->stripeClientProphecy = $this->prophesize(Stripe::class);
        $paymentMethod = new PaymentMethod();

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $paymentMethod->card = new StripeObject(); // @phpstan-ignore assign.propertyType

        /** @psalm-suppress UndefinedMagicPropertyAssignment */
        $paymentMethod->card->brand = 'visa'; // @phpstan-ignore property.notFound

        /** @psalm-suppress UndefinedMagicPropertyAssignment */
        $paymentMethod->card->country = 'gb'; // @phpstan-ignore property.notFound


        $this->stripeClientProphecy->retrievePaymentMethod(Argument::any(), Argument::any())->willReturn($paymentMethod);
    }

    private function whenAPreauthorizedDonationsChargeFails(Donation $secondDonation): void
    {
        $rateLimiterFactory = new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage());

        $donationService = new DonationService(
            $this->createStub(Allocator::class),
            $this->createStub(DonationRepository::class),
            $this->createStub(CampaignRepository::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->stripeClientProphecy->reveal(),
            $this->createStub(MatchingAdapter::class),
            $this->createStub(StripeChatterInterface::class),
            new MockClock($this->clock->now()->modify('+2 week')),
            $rateLimiterFactory,
            $this->donorAccountRepositoryProphecy->reveal(),
            $this->createStub(RoutableMessageBus::class),
            $this->createStub(DonationNotifier::class),
            $this->createStub(FundRepository::class),
            $this->createStub(\Redis::class),
            $rateLimiterFactory,
            $this->sut,
        );

        $paymentIntent = new PaymentIntent();
        $paymentIntent->status = PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD; // really other than success should have the same effect.

        $this->stripeClientProphecy->confirmPaymentIntent($secondDonation->getTransactionId(), Argument::type('array'))
            ->willReturn($paymentIntent);

        $this->stripeClientProphecy->updatePaymentIntent("transaction_id", Argument::type('array'))->shouldBeCalled();

        $donationService->confirmPreAuthorized($secondDonation);
    }
}
