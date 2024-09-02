<?php

namespace MatchBot\Domain;

use Brick\DateTime\LocalDate;
use Brick\DateTime\LocalDateTime;
use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;
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
    private const int MIN_AMOUNT_PENCE = 1_00;

    private const int MAX_AMOUNT_PENCE = 500_00;

    #[ORM\Column(unique: true, type: 'uuid')]
    public readonly UuidInterface $uuid;

    #[ORM\Embedded(columnPrefix: 'person')]
    public PersonId $donorId;

    #[ORM\Embedded(columnPrefix: '')]
    public readonly Money $amount;

    #[ORM\Column()]
    private readonly string $campaignId;

    #[ORM\Column()]
    public readonly string $charityId;

    #[ORM\Column()]
    private readonly bool $giftAid;

    #[ORM\Embedded(columnPrefix: false)]
    private DayOfMonth $dayOfMonth;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activeFrom = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $donationsCreatedUpTo = null;

    #[ORM\Column(type: 'string', enumType: MandateStatus::class)]
    private MandateStatus $status = MandateStatus::Pending;

    /**
     * @param Salesforce18Id<Campaign> $campaignId
     * @param Salesforce18Id<Charity> $charityId
     */
    public function __construct(
        PersonId $donorId,
        Money $amount,
        Salesforce18Id $campaignId,
        Salesforce18Id $charityId,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
    ) {
        $minAmount = Money::fromPence(self::MIN_AMOUNT_PENCE, Currency::GBP);
        $maxAmount = Money::fromPence(self::MAX_AMOUNT_PENCE, Currency::GBP);
        if ($amount->lessThan($minAmount) || $amount->moreThan($maxAmount)) {
            throw new UnexpectedValueException(
                "Amount {$amount} is out of allowed range {$minAmount}-{$maxAmount}"
            );
        }

        $this->uuid = Uuid::uuid4();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        $this->amount = $amount;
        $this->campaignId = $campaignId->value;
        $this->charityId = $charityId->value;
        $this->giftAid = $giftAid;
        $this->donorId = $donorId;
        $this->dayOfMonth = $dayOfMonth;
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
        Assertion::same($charity->salesforceId, $this->charityId);

        return [
            'id' => $this->uuid->toString(),
            'donorId' => $this->donorId->id,
            'amount' => $this->amount,
            'campaignId' => $this->campaignId,
            'charityId' => $this->charityId,
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

    public function firstPaymentDayAfter(\DateTimeImmutable $currentDateTime): \DateTimeImmutable
    {
        // We only operate in UK market so all timestamps should be for this TZ:
        // Not sure why some timestamps generated in tests are having TZ names BST or GMT rather than London,
        // but that's also OK.
        Assertion::inArray($currentDateTime->getTimezone()->getName(), ['Europe/London', 'BST', 'GMT']);

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
        Campaign $campaign
    ): Donation {
        $donation = new Donation(
            $this->amount->toNumericString(),
            $this->amount->currency->isoCode(),
            PaymentMethodType::Card,
            $campaign,
            false,
            false,
            $donor->stripeCustomerId->stripeCustomerId,
            false,
            $donor->donorName,
            $donor->emailAddress,
            'GB', // @todo - take country code from donor.
            '0'
        );

        $donation->update(
            giftAid: $this->giftAid,
            tipGiftAid: false,
            donorHomeAddressLine1: 'Address line 1', // @todo - take address from donor
            donorHomePostcode: 'SW1A 1AA', // @todo - take postcode from donor
            donorName: $donor->donorName,
            donorEmailAddress: $donor->emailAddress,
            tbgComms: false,
            charityComms: false,
            championComms: false,
            donorBillingPostcode: 'SW1A 1AA', // @todo - take postcode from donor
        );

        if ($this->activeFrom === null) {
            throw new \Exception('Missing activation date - is this an active mandate?');
        }

        $secondDonationDate = $this->firstPaymentDayAfter($this->activeFrom);

        if ($sequenceNumber->number < 2) {
            // first donation in mandate should be taken on-session, not pre-authorized.
            throw new \Exception('Cannot generate pre-authorized first donation');
        }

        $offset = $sequenceNumber->number - 2;

        $preAuthorizationdate = $secondDonationDate->modify("+$offset months");

        \assert($preAuthorizationdate instanceof \DateTimeImmutable);

        $donation->preAuthorize($preAuthorizationdate);

        return $donation;
    }
}
