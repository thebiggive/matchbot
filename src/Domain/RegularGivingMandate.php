<?php

namespace MatchBot\Domain;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use UnexpectedValueException;

/**
 * @psalm-suppress UnusedProperty - properties being brought into use now
 */
#[ORM\Table]
#[ORM\Index(name: 'uuid', columns: ['uuid'])]
#[ORM\Index(name: 'donationsCreatedUpTo', columns: ['donationsCreatedUpTo'])]
#[ORM\Entity(
    repositoryClass: null // we construct our own repository
)]
#[ORM\HasLifecycleCallbacks]
class RegularGivingMandate extends SalesforceWriteProxy
{
    use TimestampsTrait;

    private const int MIN_AMOUNT_PENCE = 1_00;

    private const int MAX_AMOUNT_PENCE = 500_00;

    /**
     * The first donations taken for a regular giving mandate are usually matched, later donations are not. However,
     * donors can elect to make an unmatched mandate so this does not always apply. To get the number matched for
     * a specific donation use getNumberofMatchedDonations, which is why this is private.
     *
     * @see RegularGivingMandate::getNumberofMatchedDonations()
     */
    private const int NUMBER_OF_DONATIONS_TO_MATCH = 3;

    #[ORM\Column(unique: true, type: 'uuid')]
    private readonly UuidInterface $uuid;

    #[ORM\Embedded(columnPrefix: 'person')]
    private PersonId $donorId;

    #[ORM\Embedded(columnPrefix: '')]
    private readonly Money $donationAmount;

    /**
     * @var string 18 digit salesforce ID of campaign
     */
    #[ORM\Column()]
    private readonly string $campaignId;

    /**
     * @var string 18 digit salesforce ID of charity
     */
    #[ORM\Column()]
    private readonly string $charityId;

    #[ORM\Column()]
    private readonly bool $giftAid;


    /**
     * @var bool When the mandate was created, did the donor give or refuse permission for Big Give to send marketing
     * emails. Similar to @see Donation::$tbgComms
     */
    #[ORM\Column()]
    private readonly bool $tbgComms;

    /**
     * @var bool When the mandate was created, did the donor give or refuse permission for the charity they're donating to
     * to send marketing emails. Similar to @see Donation::$charityComms
     */
    #[ORM\Column()]
    private readonly bool $charityComms;

    #[ORM\Embedded(columnPrefix: false)]
    private DayOfMonth $dayOfMonth;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activeFrom = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $donationsCreatedUpTo = null;

    #[ORM\Column(type: 'string', enumType: MandateStatus::class)]
    private MandateStatus $status = MandateStatus::Pending;

    /**
     * Whether the first donations should be matched - if match funds are not available we will allow donors
     * to create an unmatched mandate. If false then donations may still end up incidentally matched e.g. via match
     * funds redistribution at campaign end.
     */
    #[ORM\Column()]
    private bool $isMatched;

    /**
     * @param Salesforce18Id<Campaign> $campaignId
     * @param Salesforce18Id<Charity> $charityId
     *
     * @throws UnexpectedValueException if the amount is out of the allowed range
     */
    public function __construct(
        PersonId $donorId,
        Money $donationAmount,
        Salesforce18Id $campaignId,
        Salesforce18Id $charityId,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
        bool $tbgComms = false,
        bool $charityComms = false,
        bool $matchDonations = true,
    ) {
        $this->createdNow();
        $minAmount = Money::fromPence(self::MIN_AMOUNT_PENCE, Currency::GBP);
        $maxAmount = Money::fromPence(self::MAX_AMOUNT_PENCE, Currency::GBP);
        if ($donationAmount->lessThan($minAmount) || $donationAmount->moreThan($maxAmount)) {
            throw new UnexpectedValueException(
                "Amount {$donationAmount} is out of allowed range {$minAmount}-{$maxAmount}"
            );
        }

        $this->uuid = Uuid::uuid4();

        $this->donationAmount = $donationAmount;
        $this->campaignId = $campaignId->value;
        $this->charityId = $charityId->value;
        $this->giftAid = $giftAid;
        $this->donorId = $donorId;
        $this->dayOfMonth = $dayOfMonth;
        $this->tbgComms = $tbgComms;
        $this->charityComms = $charityComms;
        $this->isMatched = $matchDonations;
    }

    /**
     * Returns the average matched amount of the given donations, rounded down to the nearest major unit.
     *
     * This indicates the largest donation size that it would be possible to match with the same match funds, assuming
     * it would be spread across the same number of donations.
     *
     * @param non-empty-list<Donation> $donations . Must be at least one.
     * @return Money
     */
    public static function averageMatched(array $donations): Money
    {
        $totals = array_map(fn(Donation $donation) => $donation->getFundingWithdrawalTotalAsObject(), $donations);
        $grandTotal = Money::sum(...$totals);

        $averagePence = intdiv($grandTotal->amountInPence, count($donations));

        // We know currency is same for all donations as otherwise `sum` would have thrown.
        $currency = $donations[0]->currency();
        
        $averageMoneyRoundedDownToMajorUnit = Money::fromPence(
            intdiv($averagePence, 100) * 100,
            $currency
        );

        return $averageMoneyRoundedDownToMajorUnit;
    }

    /**
     * Allows us to take payments according to this agreement from now on.
     *
     * Precondition: Must be in Pending status
     */
    public function activate(\DateTimeImmutable $activationDate): void
    {
        Assertion::eq($this->status, MandateStatus::Pending);
        $this->status = MandateStatus::Active;
        $this->activeFrom = $activationDate;
    }

    public function toFrontEndApiModel(Charity $charity, \DateTimeImmutable $now): array
    {
        Assertion::same($charity->getSalesforceId(), $this->charityId);

        return [
            'id' => $this->uuid->toString(),
            'donorId' => $this->donorId->id,
            'donationAmount' => $this->donationAmount,
            'matchedAmount' => $this->getMatchedAmount(),
            'giftAidAmount' => $this->getGiftAidAmount(),
            'totalIncGiftAid' => $this->totalIncGiftAid(),
            'totalCharityReceivesPerInitial' => $this->totalCharityReceivesPerInitial(),
            'campaignId' => $this->campaignId,
            'charityId' => $this->charityId,
            'isMatched' => $this->isMatched,
            'numberOfMatchedDonations' => $this->getNumberofMatchedDonations(),
            'schedule' => [
                'type' => 'monthly',
                'dayOfMonth' => $this->dayOfMonth->value,
                'activeFrom' => $this->activeFrom?->format(\DateTimeInterface::ATOM),
                'expectedNextPaymentDate' => $this->firstPaymentDayAfter($now)->format(\DateTimeInterface::ATOM),
            ],
            'charityName' => $charity->getName(),
            'giftAid' => $this->giftAid,
            'status' => $this->status->apiName(),
        ];
    }


    public function toSFApiModel(DonorAccount $donor): array
    {
        Assertion::eq($donor->id(), $this->donorId);

        return [
            'uuid' => $this->uuid->toString(),
            'campaignSFId' => $this->campaignId,
            'activeFrom' => $this->activeFrom?->format(DateTimeInterface::ATOM),
            'dayOfMonth' => $this->dayOfMonth->value,
            'donationAmount' => (float) $this->donationAmount->toNumericString(), // SF type is Decimal, so cast
            'status' => ucfirst($this->status->apiName()), // Field in SF has upper case first letter and is awkard to change.
            'contactUuid' => $this->donorId->id,
            'giftAid' => $this->giftAid,
            'donor' => $donor->toSfApiModel(),
        ];
    }

    public function firstPaymentDayAfter(\DateTimeImmutable $currentDateTime): \DateTimeImmutable
    {
        // We only operate in UK market so all timestamps should be for this TZ:
        // Not sure why some timestamps generated in tests are having TZ names BST or GMT rather than London,
        // but that's also OK.
        Assertion::inArray($currentDateTime->getTimezone()->getName(), ['Europe/London', 'BST', 'GMT', 'UTC']);

        $nextPaymentDayIsNextMonth = $currentDateTime->format('d') >= $this->dayOfMonth->value;

        $todayOrNextMonth = $nextPaymentDayIsNextMonth ?
            $currentDateTime->add(new \DateInterval("P1M")) :
            $currentDateTime;

        return new \DateTimeImmutable(
            $todayOrNextMonth->format('Y-m-' . $this->dayOfMonth->value . 'T06:00:00'),
            $currentDateTime->getTimezone()
        );
    }

    public function hasGiftAid(): bool
    {
        return $this->giftAid;
    }

    /**
     * Records that all donations we plan to take for this donation before the given time have been created
     * and saved as pre-authorized donations. This means that no more donations need to be created based on this
     * mandate before that date.
     *
     * @psalm-suppress PossiblyUnusedMethod - to be used soon.
     */
    public function setDonationsCreatedUpTo(?\DateTimeImmutable $donationsCreatedUpTo): void
    {
        Assertion::same($this->status, MandateStatus::Active);
        $this->donationsCreatedUpTo = $donationsCreatedUpTo;
    }

    public function createPreAuthorizedDonation(
        DonationSequenceNumber $sequenceNumber,
        DonorAccount $donor,
        Campaign $campaign,
        bool $requireActiveMandate = true,
        \DateTimeImmutable $expectedActivationDate = null,
    ): Donation {
        // comms prefs below (charityComms, championComms, optInTbgEmail) are all set to null, as it's only the 1st
        // donation in the mandate that carries the donor's chosen marketing comms preferences to Salesforce.

        // It may be possible that gift aid was selected when this mandate was created but since then the donor
        // told us to forget their home address. In that case we wouldn't be able to claim gift aid for any
        // new donations.
        $giftAidClaimable = $this->giftAid && $donor->hasHomeAddress();

        $donation = new Donation(
            amount: $this->donationAmount->toNumericString(),
            currencyCode: $this->donationAmount->currency->isoCode(),
            paymentMethodType: PaymentMethodType::Card,
            campaign: $campaign,
            charityComms: null,
            championComms: null,
            pspCustomerId: $donor->stripeCustomerId->stripeCustomerId,
            optInTbgEmail: null,
            donorName: $donor->donorName,
            emailAddress: $donor->emailAddress,
            countryCode: $donor->getBillingCountryCode(),
            tipAmount: '0',
            mandate: $this,
            mandateSequenceNumber: $sequenceNumber,
            giftAid: $giftAidClaimable,
            tipGiftAid: null,
            homeAddress: $donor->getHomeAddressLine1(),
            homePostcode: $donor->getHomePostcode(),
            billingPostcode: null,
        );

        Assertion::true(
            ($requireActiveMandate && is_null($expectedActivationDate)) ||
            (!$requireActiveMandate && !is_null($expectedActivationDate)),
            'When creating donations for an already active mandate require the mandate to be active, otherwise pass the activation date'
        );

        $donation->update(
            giftAid: $this->giftAid,
            tipGiftAid: false,
            donorHomeAddressLine1: $donor->getHomeAddressLine1(),
            donorHomePostcode: $donor->getHomePostcode(),
            donorName: $donor->donorName,
            donorEmailAddress: $donor->emailAddress,
            tbgComms: false,
            charityComms: false,
            championComms: false,
            donorBillingPostcode: $donor->getBillingPostcode(),
        );

        if ($this->activeFrom === null && $requireActiveMandate) {
            throw new \Exception('Missing activation date - is this an active mandate?');
        }

        $assumedActivateDate = $this->activeFrom ?? $expectedActivationDate;

        \assert($assumedActivateDate !== null); // can't be null based on combination of previous assertions.

        $secondDonationDate = $this->firstPaymentDayAfter($assumedActivateDate);

        if ($sequenceNumber->number < 2) {
            // first donation in mandate should be taken on-session, not pre-authorized.
            throw new \Exception('Cannot generate pre-authorized first donation');
        }

        $offset = $sequenceNumber->number - 2;

        $preAuthorizationdate = $secondDonationDate->modify("+$offset months");
        Assertion::isInstanceOf($preAuthorizationdate, \DateTimeImmutable::class);

        if ($campaign->regularGivingCollectionIsEndedAt($preAuthorizationdate)) {
            $collectionEnd = $campaign->getRegularGivingCollectionEnd();
            Assertion::notNull($collectionEnd);

            throw new RegularGivingCollectionEndPassed(
                "Cannot pre-authorize a donation for {$preAuthorizationdate->format('Y-m-d')}, " .
                "regular giving collections for campaign {$campaign->getSalesforceId()} end " .
                "at {$collectionEnd->format('Y-m-d')}"
            );
        }

        $donation->preAuthorize($preAuthorizationdate);

        return $donation;
    }

    /**
     * @return Salesforce18Id<Campaign>
     */
    public function getCampaignId(): Salesforce18Id
    {
        return Salesforce18Id::ofCampaign($this->campaignId);
    }

    public function getUuid(): UuidInterface
    {
        return $this->uuid;
    }

    public function getDonationAmount(): Money
    {
        return $this->donationAmount;
    }

    public function getCharityId(): string
    {
        return $this->charityId;
    }

    public function cancel(): void
    {
        $this->status = MandateStatus::Cancelled;
    }

    public function getStatus(): MandateStatus
    {
        return $this->status;
    }

    public function campaignEnded(): void
    {
        $this->status = MandateStatus::CampaignEnded;
    }

    public function getActiveFrom(): ?\DateTimeImmutable
    {
        return $this->activeFrom;
    }

    public function describeSchedule(): string
    {
        return "Monthly on day #{$this->dayOfMonth->value}";
    }

    /**
     * @return Money amount we expect to be claimable in gift aid per donation.
     */
    public function getGiftAidAmount(): Money
    {
        if (! $this->giftAid) {
            return Money::fromPoundsGBP(0);
        }

        return $this->donationAmount->withPence(
            (int) (100 * Donation::donationAmountToGiftAidValue(amount: $this->donationAmount->toNumericString()))
        );
    }

    public function totalIncGiftAid(): Money
    {
        return $this->donationAmount->plus($this->getGiftAidAmount());
    }


    /**
     * @return Money The total amount that we expect the charity to receive per each of the donors initial, matched
     * donations, from us and HMRC in total. I.e. core amount + matched amount + gift aid amount.
     *
     */
    private function totalCharityReceivesPerInitial(): Money
    {
        return Money::sum($this->donationAmount, $this->getGiftAidAmount(), $this->getMatchedAmount());
    }

    public function getMatchedAmount(): Money
    {
        return $this->isMatched ?  $this->donationAmount : Money::zero($this->donationAmount->currency);
    }

    public function createPendingFirstDonation(Campaign $campaign, DonorAccount $donor): Donation
    {
        Assertion::same($campaign->getSalesforceId(), $this->campaignId);

        // As this is the first donation in the mandate we give it a copy of the donor's Big Give and Charity comms
        // preferences so that SF can pick them up.
        return new Donation(
            amount: $this->donationAmount->toNumericString(),
            currencyCode: $this->donationAmount->currency->isoCode(),
            paymentMethodType: PaymentMethodType::Card,
            campaign: $campaign,
            charityComms: $this->charityComms,
            championComms: null,
            pspCustomerId: $donor->stripeCustomerId->stripeCustomerId,
            optInTbgEmail: $this->tbgComms,
            donorName: $donor->donorName,
            emailAddress: $donor->emailAddress,
            countryCode: $donor->getBillingCountryCode(),
            tipAmount: '0',
            mandate: $this,
            mandateSequenceNumber: DonationSequenceNumber::of(1),
            giftAid: $this->giftAid,
            billingPostcode: $donor->getBillingPostcode(),
            homeAddress: $donor->getHomeAddressLine1(),
            homePostcode: $donor->getHomePostcode(),
        );
    }

    public function donorId(): PersonId
    {
        return $this->donorId;
    }

    public function isMatched(): bool
    {
        return $this->isMatched;
    }

    public function getNumberofMatchedDonations(): int
    {
        return $this->isMatched ? self::NUMBER_OF_DONATIONS_TO_MATCH : 0;
    }
}
