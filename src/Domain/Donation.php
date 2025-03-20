<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assert;
use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\LazyAssertionException;
use MatchBot\Domain\DomainException\CannotRemoveGiftAid;
use MatchBot\Domain\DomainException\RegularGivingDonationToOldToCollect;
use Messages;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

use function bccomp;
use function sprintf;

#[ORM\Table]
#[ORM\UniqueConstraint(fields: ['mandateSequenceNumber', 'mandate'])]
#[ORM\Index(name: 'campaign_and_status', columns: ['campaign_id', 'donationStatus'])]
#[ORM\Index(name: 'date_and_status', columns: ['createdAt', 'donationStatus'])]
#[ORM\Index(name: 'updated_date_and_status', columns: ['updatedAt', 'donationStatus'])]
#[ORM\Index(name: 'salesforcePushStatus', columns: ['salesforcePushStatus'])]
#[ORM\Index(name: 'pspCustomerId', columns: ['pspCustomerId'])]
#[ORM\Entity(repositoryClass: DoctrineDonationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Donation extends SalesforceWriteProxy
{
    use TimestampsTrait;

    /**
     * @see Donation::$currencyCode
     */
    public const int MAXIMUM_CARD_DONATION = 25_000;

    public const int MAXIMUM_CUSTOMER_BALANCE_DONATION = 200_000;
    public const int MINUMUM_AMOUNT = 1;
    public const string GIFT_AID_PERCENTAGE = '25';

    /**
     * Placeholder used in home postcode field for a donor with a home outside the UK. See also
     * OVERSEAS constant in donate-frontend.
     */
    public const string OVERSEAS = 'OVERSEAS';

    public const string MAT_400_ENABLE_TIMESTAMP = '2025-03-18T14:30:00+00:00';

    private array $possiblePSPs = ['stripe'];

    /**
     * The donation ID for PSPs and public APIs. Not the same as the internal auto-increment $id used
     * by Doctrine internally for fast joins.
     *
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    protected UuidInterface $uuid;

    /**
     * @var Campaign
     */
    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    protected Campaign $campaign;

    /**
     * @var string  Which Payment Service Provider (PSP) is expected to (or did) process the donation.
     */
    #[ORM\Column(type: 'string', length: 20)]
    protected string $psp;

    /**
     * @var ?DateTimeImmutable  When the donation first moved to status Collected, i.e. the donor finished paying.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $collectedAt = null;

    /**
     * @var string|null PSP's transaction ID assigned on their processing.
     *
     * In the case of stripe (which is the only thing we support at present, this is the payment intent ID)
     */
    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    protected ?string $transactionId = null;

    /**
     * @var string|null PSP's charge ID assigned on their processing.
     */
    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    protected ?string $chargeId = null;

    /**
     * @var string|null PSP's transfer ID assigned on a successful charge. For Stripe this
     *                  ID relates to the Platform Account (i.e. the Big Give's) rather than
     *                  the Connected Account for the charity receiving the transferred
     *                  donation balance.
     */
    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    protected ?string $transferId = null;

    /**
     * @var string  ISO 4217 code for the currency in which all monetary values are denominated, e.g. 'GBP'.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected readonly string $currencyCode;

    /**
     * Core donation amount in major currency units (i.e. Pounds) excluding any tip.
     *
     * @psalm-var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths
     *                           with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected readonly string $amount;


    /**
     * Total amount paid by donor - recorded from the Stripe charge, and reduced to reflect the new total
     * if we issue a tip refund (but not if we issue a full refund).
     *
     * Null for donation collected before August 2024, as we didn't record it at the time.
     *
     * @psalm-var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths
     *                           with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2, nullable: true)]
    private ?string $totalPaidByDonor = null;

    /**
     * Fee the charity takes on, in £. Excludes any tax if applicable.
     *
     * For Stripe (EU / UK): 1.5% of $amount + 0.20p
     * For Stripe (Non EU / Amex): 3.2% of $amount + 0.20p
     *
     * @var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $charityFee = '0.00';

    /**
     * Value Added Tax amount on `$charityFee`, in £. In addition to base amount
     * in $charityFee.
     *
     * @var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $charityFeeVat = '0.00';

    /**
     * Fee charged to TBG by the PSP, in £. Added just for Stripe transactions from March '21.
     * Original fee varies by card brand and country and is based on the full amount paid by the
     * donor: `$amount + $tipAmount`.
     *
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $originalPspFee = '0.00';

    #[ORM\Column(type: 'string', enumType: DonationStatus::class)]
    protected DonationStatus $donationStatus = DonationStatus::Pending;

    /**
     * @var bool    Whether the donor opted to receive email from the charity running the campaign
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $charityComms = null;

    /**
     * Whether the Donor has asked for Gift Aid to be claimed about this donation.
     */
    #[ORM\Column()]
    protected bool $giftAid = false;

    /**
     * Date at which we amended the donation to cancel claiming gift aid.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $giftAidRemovedAt = null;

    /**
     * @var bool    Whether the donor opted to receive email from the Big Give
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tbgComms = null;

    /**
     * @var bool    Whether the donor opted to receive email from the champion funding the campaign
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $championComms = null;

    /**
     * @var string|null  *Billing* country code.
     */
    #[ORM\Column(type: 'string', length: 2, nullable: true)]
    protected ?string $donorCountryCode = null;

    /**
     * Ideally we would type this as ?EmailAddress instead of ?string but that will require changing
     * the column name to match the property inside the VO. Might be easy and worth doing.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorEmailAddress = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorFirstName = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorLastName = null;

    /**
     * Position in sequence of donations taken in relation to a regular giving mandate, e.g. 1st
     * (taken at mandate creation time), 2nd, 3rd etc.
     *
     * Null only iff this is a one-off, non regular-giving donation.
     *
     * @psalm-suppress PossiblyUnusedProperty - used in DQL
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $mandateSequenceNumber = null;

    /**
     * @psalm-suppress PossiblyUnusedProperty - used in DQL
     */
    #[ORM\ManyToOne(targetEntity: RegularGivingMandate::class)]
    private ?RegularGivingMandate $mandate = null;

    /**
     * Previously known as donor postal address,
     * and may still be called that in other systems,
     * but now used for billing postcode only. Some old
     * donations from 2022 or earlier have full addresses here.
     *
     * May be a post code or equivilent from anywhere in the world,
     * so we allow up to 15 chars which has been enough for all donors in the last 12 months.
     *
     * @var string|null
     */
    #[ORM\Column(type: 'string', nullable: true, name: 'donorPostalAddress')]
    protected ?string $donorBillingPostcode = null;

    /**
     * @var string|null From residential address, if donor is claiming Gift Aid.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorHomeAddressLine1 = null;

    /**
     * @var string|null From residential address, if donor is claiming Gift Aid.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorHomePostcode = null;

    /**
     * @var numeric-string  Amount donor chose to tip. Precision numeric string.
     *              Set during donation setup and can also be modified later if the donor changes only this.
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $tipAmount = '0.00';

    /**
     * @var numeric-string  Amount refunded to donor in case of accidental tip.
     *
     * Only set on donations from Feb 2025 and later.
     *
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2, nullable: true)]
    protected ?string $tipRefundAmount = null;

    /**
     * @var bool    Whether Gift Aid was claimed on the 'tip' donation to the Big Give.
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tipGiftAid = null;

    /**
     * @var bool    Whether any Gift Aid claim should be made by the Big Give as an agent/nominee
     *              *if* `$giftAid is true too. This field is set independently to allow for claim
     *              status amendments so we must not assume a donation can actualy be claimed just
     *              because it's true.
     * @see Donation::$giftAid
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tbgShouldProcessGiftAid = null;

    /**
     * @psalm-suppress PossiblyUnusedProperty - used in DB queries
     * @var ?DateTimeImmutable When a queued message that should lead to a Gift Aid claim was sent.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTimeImmutable $tbgGiftAidRequestQueuedAt = null;

    /**
     * @var ?DateTime   When a claim submission attempt was detected to have an error returned.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $tbgGiftAidRequestFailedAt = null;

    /**
     * @var ?DateTime   When a claim was detected accepted via an async poll.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $tbgGiftAidRequestConfirmedCompleteAt = null;

    /**
     * @var ?string Provided by HMRC upon initial claim submission acknowledgement.
     *              Doesn't imply success.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $tbgGiftAidRequestCorrelationId = null;

    /**
     * @var ?string Verbatim final errors or messages from HMRC received immediately or
     *              (most likely based on real world observation) via an async poll.
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    protected ?string $tbgGiftAidResponseDetail = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $pspCustomerId = null;

    #[ORM\Column(type: 'string', enumType: PaymentMethodType::class, nullable: true)]
    protected ?PaymentMethodType $paymentMethodType = PaymentMethodType::Card;

    /**
     * @var Collection<int,FundingWithdrawal>
     */
    #[ORM\OneToMany(targetEntity: FundingWithdrawal::class, mappedBy: 'donation', fetch: 'EAGER')]
    protected $fundingWithdrawals;

    /**
     * Date at which we refunded this to the donor. Ideally will be null. Should be not null only iff status is
     * DonationStatus::Refunded
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    /**
     * We only have permission to collect a preAuthorized donation on or after the given date. Intended to be used
     * with regular giving.
     *
     * @psalm-suppress UnusedProperty (will use soon)
     *
     * @see DonationStatus::PreAuthorized
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $preAuthorizationDate = null;

    /**
     * @param string|null $billingPostcode
     * @psalm-param numeric-string $amount
     * @psalm-param ?numeric-string $tipAmount
     */
    public function __construct(
        string $amount,
        string $currencyCode,
        PaymentMethodType $paymentMethodType,
        Campaign $campaign,
        ?bool $charityComms,
        ?bool $championComms,
        ?string $pspCustomerId,
        ?bool $optInTbgEmail,
        ?DonorName $donorName,
        ?EmailAddress $emailAddress,
        ?string $countryCode,
        ?string $tipAmount,
        ?RegularGivingMandate $mandate,
        ?DonationSequenceNumber $mandateSequenceNumber,
        bool $giftAid = false,
        ?bool $tipGiftAid = null,
        ?string $homeAddress = null,
        ?string $homePostcode = null,
        ?string $billingPostcode = null,
    ) {
        $this->setUuid(Uuid::uuid4());
        $this->fundingWithdrawals = new ArrayCollection();
        $this->currencyCode = $currencyCode;
        $maximumAmount = self::maximumAmount($paymentMethodType);

        if (
            bccomp($amount, (string)self::MINUMUM_AMOUNT, 2) === -1 ||
            bccomp($amount, (string)$maximumAmount, 2) === 1
        ) {
            throw new \UnexpectedValueException(sprintf(
                'Amount %s is out of allowed range %d-%d %s',
                $amount,
                self::MINUMUM_AMOUNT,
                $maximumAmount,
                $this->currencyCode,
            ));
        }

        $this->amount = $amount;
        $this->paymentMethodType = $paymentMethodType;
        $this->createdNow(); // Mimic ORM persistence hook attribute, calling its fn explicitly instead.
        $this->setPsp('stripe');
        $this->setCampaign($campaign); // Charity & match expectation determined implicitly from this
        $this->setTbgShouldProcessGiftAid($campaign->getCharity()->isTbgClaimingGiftAid());
        $this->setCharityComms($charityComms);
        $this->setChampionComms($championComms);
        $this->setPspCustomerId($pspCustomerId);
        $this->setTbgComms($optInTbgEmail);
        $this->setDonorName($donorName);
        $this->setDonorEmailAddress($emailAddress);

        $this->giftAid = $giftAid;
        $this->tipGiftAid = $tipGiftAid;
        $this->donorHomeAddressLine1 = $homeAddress;
        $this->donorHomePostcode = $homePostcode;

        // We probably don't need to test for all these, just replicationg behaviour of `empty` that was used before.
        if ($countryCode !== '' && $countryCode !== null && $countryCode !== '0') {
            $this->setDonorCountryCode(strtoupper($countryCode));
        }

        if (isset($tipAmount)) {
            $this->setTipAmount($tipAmount);
        }

        $this->mandate = $mandate;
        $this->mandateSequenceNumber = $mandateSequenceNumber?->number;
        $this->donorBillingPostcode = $billingPostcode;
    }

    /**
     * @throws \Assert\AssertionFailedException
     * @throws \UnexpectedValueException
     */
    public static function fromApiModel(DonationCreate $donationData, Campaign $campaign): Donation
    {
        Assertion::eq($donationData->psp, 'stripe');
        return new self(
            amount: $donationData->donationAmount,
            currencyCode: $donationData->currencyCode,
            paymentMethodType: $donationData->pspMethodType,
            campaign: $campaign,
            charityComms: $donationData->optInCharityEmail,
            championComms: $donationData->optInChampionEmail,
            pspCustomerId: $donationData->pspCustomerId,
            optInTbgEmail: $donationData->optInTbgEmail,
            donorName: $donationData->donorName,
            emailAddress: $donationData->emailAddress,
            countryCode: $donationData->countryCode,
            tipAmount: $donationData->tipAmount,
            mandate: null,
            mandateSequenceNumber: null,
            // Main form starts off with this null on init in the API model, so effectively it's ignored here
            // then as `false` is also the constructor's default. Donation Funds tips should send a bool value
            // from the start.
            giftAid: $donationData->giftAid ?? false,
            // Not meaningfully used yet (typical donations set it on Update instead; Donation Funds
            // tips don't have a "tip" because the donation is to BG), but map just in case.
            tipGiftAid: $donationData->tipGiftAid,
            homeAddress: $donationData->homeAddress,
            homePostcode: $donationData->homePostcode,
            billingPostcode: null, // no support for billing post code on donation creation in API - only on update.
        );
    }

    private static function maximumAmount(PaymentMethodType $paymentMethodType): int
    {
        return match ($paymentMethodType) {
            PaymentMethodType::CustomerBalance => self::MAXIMUM_CUSTOMER_BALANCE_DONATION,
            PaymentMethodType::Card => self::MAXIMUM_CARD_DONATION,
        };
    }

    /**
     * Multiples by 25%
     *
     * @param numeric-string $amount
     * @return numeric-string
     */
    public static function donationAmountToGiftAidValue(string $amount): string
    {
        $giftAidFactor = bcdiv(self::GIFT_AID_PERCENTAGE, '100', 2);
        return bcmul($amount, $giftAidFactor, 2);
    }

    public function __toString(): string
    {
        // if we're in __toString then probably something has already gone wrong, and we don't want to allow
        // any more crashes during the logging process.
        try {
            $charityName = $this->getCampaign()->getCharity()->getName();
        } catch (\Throwable $t) {
            // perhaps the charity was never pulled from Salesforce into our database, in which case we might
            // have a TypeError trying to get a string name from it.
            $charityName = "[pending charity threw " . get_class($t) . "]";
        }
        $id = is_null($this->id) ? 'non-persisted' : "#{$this->id}";
        return "Donation $id {$this->getUuid()} to $charityName";
    }

    /*
     * In contrast to __toString, this is used when creating a payment intent. If we can't find the charity name
     * then we should let the process of making the intent and registering the donation crash.
     */
    public function getDescription(): string
    {
        $charityName = $this->getCampaign()->getCharity()->getName();
        return "Donation {$this->getUuid()} to $charityName";
    }

    /**
     * @param PreUpdateEventArgs $args
     * @throws \LogicException if amount is changed
     */
    #[ORM\PreUpdate]
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('amount')) {
            return;
        }

        if ($args->getOldValue('amount') !== $args->getNewValue('amount')) {
            throw new \LogicException('Amount may not be changed after a donation is created');
        }
    }

    public function toSFApiModel(): array
    {
        $data = [
            ...$this->toFrontEndApiModel(),
            'originalPspFee' => (float) $this->getOriginalPspFee(),
            'tipRefundAmount' => $this->getTipRefundAmount()?->toMajorUnitFloat(),

            // replace with hard-coded true when date is passed.
            'confirmationByMatchbot' => $this->donationStatus->isSuccessful() &&
                $this->getCollectedAt() > new \DateTimeImmutable(self::MAT_400_ENABLE_TIMESTAMP),
        ];

        // As of mid 2024 only the actual donate frontend gets this value, to avoid
        // confusion around values that are too temporary to be useful in a CRM anyway.
        unset($data['matchReservedAmount']);

        if ($this->mandate) {
            $data['mandate'] = [
              'salesforceId' => $this->mandate->getSalesforceId(),
            ];
        }

        return $data;
    }

    public function toFrontEndApiModel(): array
    {
        $totalPaidByDonor = $this->getTotalPaidByDonor();

        $fundingWithdrawalsByType = $this->getWithdrawalTotalByFundType();

        $data = [
            'amountMatchedByChampionFunds' => (float) $fundingWithdrawalsByType['amountMatchedByChampionFunds'],
            'amountMatchedByPledges' => (float) $fundingWithdrawalsByType['amountMatchedByPledges'],
            'amountPreauthorizedFromChampionFunds' => (float) $fundingWithdrawalsByType['amountPreauthorizedFromChampionFunds'],
            'billingPostalAddress' => $this->donorBillingPostcode,
            'charityFee' => (float) $this->getCharityFee(),
            'charityFeeVat' => (float) $this->getCharityFeeVat(),
            'charityId' => $this->getCampaign()->getCharity()->getSalesforceId(),
            'charityName' => $this->getCampaign()->getCharity()->getName(),
            'countryCode' => $this->getDonorCountryCode(),
            'collectedTime' => $this->getCollectedAt()?->format(DateTimeInterface::ATOM),
            'createdTime' => $this->getCreatedDate()->format(DateTimeInterface::ATOM),
            'currencyCode' => $this->currency()->isoCode(),
            'donationAmount' => (float) $this->getAmount(),
            'totalPaid' => is_null($totalPaidByDonor) ? null : (float)$totalPaidByDonor,
            'donationId' => $this->getUuid(),
            'donationMatched' => $this->getCampaign()->isMatched(),
            'emailAddress' => $this->getDonorEmailAddress()?->email,
            'firstName' => $this->getDonorFirstName(true),
            'giftAid' => $this->hasGiftAid(),
            'homeAddress' => $this->getDonorHomeAddressLine1(),
            'homePostcode' => $this->getDonorHomePostcode(),
            'lastName' => $this->getDonorLastName(true),
            'matchedAmount' => $this->matchedAmount()->toMajorUnitFloat(),
            'matchReservedAmount' => 0,
            'optInCharityEmail' => $this->getCharityComms(),
            'optInChampionEmail' => $this->getChampionComms(),
            'optInTbgEmail' => $this->getTbgComms(),
            'projectId' => $this->getCampaign()->getSalesforceId(),
            'psp' => $this->getPsp(),
            'pspCustomerId' => $this->getPspCustomerId()?->stripeCustomerId,
            'pspMethodType' => $this->getPaymentMethodType()?->value,
            'refundedTime' => $this->refundedAt?->format(DateTimeInterface::ATOM),
            'status' => $this->getDonationStatus(),
            'tbgGiftAidRequestConfirmedCompleteAt' =>
                $this->tbgGiftAidRequestConfirmedCompleteAt?->format(DateTimeInterface::ATOM),
            'tipAmount' => (float) $this->getTipAmount(),
            'tipGiftAid' => $this->hasTipGiftAid(),
            'transactionId' => $this->getTransactionId(),
            'updatedTime' => $this->getUpdatedDate()->format(DateTimeInterface::ATOM),
        ];

        if (in_array($this->getDonationStatus(), [DonationStatus::Pending, DonationStatus::PreAuthorized], true)) {
            $data['matchReservedAmount'] = (float) $this->getFundingWithdrawalTotal();
        }

        if ($this->mandate) {
            // Not including the entire mandate details as that would be wasteful, just parts FE needs to display with
            // the donation.
            $data['mandate']['uuid'] = $this->mandate->getUuid()->toString();
            $data['mandate']['activeFrom'] = $this->mandate->getActiveFrom()?->format(DateTimeInterface::ATOM);
        } else {
            $data['mandate'] = null;
        }

        return $data;
    }

    public function getDonationStatus(): DonationStatus
    {
        return $this->donationStatus;
    }

    public function setDonationStatus(DonationStatus $donationStatus): void
    {
        // todo at some point - remove this method and replace with more specific command method(s). The only non-test
        // caller now is passing DonationStatus::Paid.

        /** @psalm-suppress DeprecatedConstant */
        $this->donationStatus = match ($donationStatus) {
            DonationStatus::Refunded =>
                throw new \Exception('Donation::recordRefundAt must be used to set refunded status'),
            DonationStatus::Cancelled =>
                throw new \Exception('Donation::cancelled must be used to cancel'),
            DonationStatus::Collected =>
                throw new \Exception('Donation::collectFromStripe must be used to collect'),
            DonationStatus::Chargedback =>
                throw new \Exception('DonationStatus::Chargedback is deprecated'),

            DonationStatus::Failed,
            DonationStatus::Paid,
            DonationStatus::Pending,
            DonationStatus::PreAuthorized
            => $donationStatus,
        };
    }

    public function getCollectedAt(): ?DateTimeImmutable
    {
        return $this->collectedAt;
    }

    /**
     * @return Campaign
     */
    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    /**
     * @param Campaign $campaign
     */
    public function setCampaign(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    public function getDonorEmailAddress(): ?EmailAddress
    {
        return ((bool) $this->donorEmailAddress) ? EmailAddress::of($this->donorEmailAddress) : null;
    }

    public function setDonorEmailAddress(?EmailAddress $donorEmailAddress): void
    {
        $this->donorEmailAddress = $donorEmailAddress?->email;
    }

    public function getCharityComms(): ?bool
    {
        return $this->charityComms;
    }

    public function setCharityComms(?bool $charityComms): void
    {
        $this->charityComms = $charityComms;
    }

    public function getChampionComms(): ?bool
    {
        return $this->championComms;
    }

    public function setChampionComms(?bool $championComms): void
    {
        $this->championComms = $championComms;
    }

    public function getDonorFirstName(bool $salesforceSafe = false): ?string
    {
        $firstName = $this->donorFirstName;

        if ($salesforceSafe) {
            $firstName = $this->makeSalesforceSafe($firstName, false);
        }

        return $firstName;
    }

    public function setDonorName(?DonorName $donorName): void
    {
        $this->donorFirstName = $donorName?->first;
        $this->donorLastName = $donorName?->last;
    }

    public function getDonorLastName(bool $salesforceSafe = false): ?string
    {
        $lastName = $this->donorLastName;

        if ($salesforceSafe) {
            $lastName = $this->makeSalesforceSafe($lastName, true);
        }

        return $lastName;
    }

    public function hasGiftAid(): bool
    {
        return $this->giftAid;
    }

    private function setGiftAid(bool $giftAid): void
    {
        $this->giftAid = $giftAid;

        // Default tip Gift Aid to main Gift Aid value. If it is set explicitly
        // first this will be skipped. If set explicitly after, the later call
        // will persist.
        if ($this->tipGiftAid === null) {
            $this->tipGiftAid = $giftAid;
        }
    }

    public function getTbgComms(): ?bool
    {
        return $this->tbgComms;
    }

    public function setTbgComms(?bool $tbgComms): void
    {
        $this->tbgComms = $tbgComms;
    }

    /**
     * Get core donation amount excluding any tip or fee cover.
     *
     * @return numeric-string   In full pounds GBP.
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @return numeric-string   In full pounds GBP. Net fee if VAT is added.
     */
    public function getCharityFee(): string
    {
        return $this->charityFee;
    }

    /**
     * @return numeric-string
     */
    #[Pure] public function getCharityFeeGross(): string
    {
        return bcadd($this->getCharityFee(), $this->getCharityFeeVat(), 2);
    }

    /**
     * @param numeric-string $charityFee
     */
    public function setCharityFee(string $charityFee): void
    {
        $this->charityFee = $charityFee;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    /**
     * @return string|null
     */
    public function getTransferId(): ?string
    {
        return $this->transferId;
    }

    public function getDonorCountryCode(): ?string
    {
        return $this->donorCountryCode;
    }

    /**
     * @psalm-return numeric-string   Total amount in withdrawals - not necessarily finalised.
     */
    public function getFundingWithdrawalTotal(): string
    {
        $withdrawalTotal = '0.00';
        foreach ($this->fundingWithdrawals as $fundingWithdrawal) {
            $withdrawalTotal = bcadd($withdrawalTotal, $fundingWithdrawal->getAmount(), 2);
        }

        return $withdrawalTotal;
    }

    public function getFundingWithdrawalTotalAsObject(): Money
    {
        return Money::fromNumericString(
            $this->getFundingWithdrawalTotal(),
            Currency::fromIsoCode($this->currencyCode)
        );
    }

    /**
     * @return array{
     *     amountMatchedByChampionFunds: numeric-string,
     *     amountMatchedByPledges: numeric-string,
     *     amountPreauthorizedFromChampionFunds: numeric-string,
     *     amountMatchedOther: numeric-string,
     * }
     */
    public function getWithdrawalTotalByFundType(): array
    {
        $withdrawalTotals = [
            'amountMatchedByChampionFunds' => '0.00',
            'amountMatchedByPledges' => '0.00',
            'amountPreauthorizedFromChampionFunds' => '0.00',
            'amountMatchedOther' => '0.00', // This key is not sent to SF, covers match fund usage that we don't need to
                                           // report, i.e. for donations that are neither sucessful nor preauthed.
        ];

        foreach ($this->fundingWithdrawals as $fundingWithdrawal) {
            $fundTypeOfThisWithdrawal = $fundingWithdrawal->getCampaignFunding()->getFund()->getFundType();

            $key = match ([$fundTypeOfThisWithdrawal, $this->donationStatus->isSuccessful(), $this->donationStatus === DonationStatus::PreAuthorized  ]) {
                [FundType::ChampionFund, true, true] => throw new \LogicException("impossible status"),
                [FundType::ChampionFund, true, false] => 'amountMatchedByChampionFunds',
                [FundType::ChampionFund, false, true] => 'amountPreauthorizedFromChampionFunds',
                [FundType::ChampionFund, false, false] => 'amountMatchedOther',

                [FundType::Pledge, true, true] => throw new \LogicException("impossible status"),
                [FundType::Pledge, true, false] => 'amountMatchedByPledges',
                [FundType::Pledge, false, true] => throw new \RuntimeException("unexpected pre-authed donation using pledge fund"),
                [FundType::Pledge, false, false] => 'amountMatchedOther',

                [FundType::TopupPledge, true, true] => throw new \LogicException("impossible status"),
                [FundType::TopupPledge, true, false] => 'amountMatchedByPledges',
                [FundType::TopupPledge, false, true] => throw new \RuntimeException("unexpected pre-authed donation using top-up pledge fund"),
                [FundType::TopupPledge, false, false] => 'amountMatchedOther',
            };

            $withdrawalTotals[$key] = bcadd($withdrawalTotals[$key], $fundingWithdrawal->getAmount(), 2);
        }

        return $withdrawalTotals;
    }

    /**
     * @return string|null For a stripe based donation this is the payment intent ID. Usually set immediately for
     * each new donation, but for delayed regular giving donations will not be set until we're ready to collect
     * the payment.
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }



    public function getChargeId(): ?string
    {
        return $this->chargeId;
    }

    /**
     * @param UuidInterface $uuid
     */
    public function setUuid(UuidInterface $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function addFundingWithdrawal(FundingWithdrawal $fundingWithdrawal): void
    {
        $this->fundingWithdrawals->add($fundingWithdrawal);
    }

    /**
     * @return Collection<int, FundingWithdrawal>
     */
    public function getFundingWithdrawals(): Collection
    {
        return $this->fundingWithdrawals;
    }

    /**
     * @param string $donorCountryCode Two letter upper case code
     * @throws \UnexpectedValueException if code is does not match format.
     *
     */
    public function setDonorCountryCode(string $donorCountryCode): void
    {
        try {
            Assertion::length($donorCountryCode, 2);
            Assertion::regex($donorCountryCode, '/^[A-Z][A-Z]$/');
        } catch (AssertionFailedException $e) {
            throw new \UnexpectedValueException(message: $e->getMessage(), previous: $e);
        }

        $this->donorCountryCode = $donorCountryCode;
    }

    /**
     * @return string   Payment Service Provider short identifier, e.g. 'stripe'.
     */
    public function getPsp(): string
    {
        return $this->psp;
    }

    /**
     * @param string $psp   Payment Service Provider short identifier, e.g. 'stripe'.
     */
    private function setPsp(string $psp): void
    {
        if (!in_array($psp, $this->possiblePSPs, true)) {
            throw new \UnexpectedValueException("Unexpected PSP '$psp'");
        }

        $this->psp = $psp;
    }

    /**
     * @return numeric-string
     */
    public function getTipAmount(): string
    {
        return $this->tipAmount;
    }

    public function setTipAmount(string $tipAmount): void
    {
        /** @psalm-var numeric-string $tipAmount */
        if (
            $this->paymentMethodType === PaymentMethodType::CustomerBalance &&
            bccomp($tipAmount, '0', 2) !== 0
        ) {
            // We would have accepted a tip at the time the customer balance was created, so we don't take a second
            // tip as part of the donation.
            throw new \UnexpectedValueException('A Customer Balance Donation may not include a tip');
        }

        $max = self::MAXIMUM_CARD_DONATION;

        if (bccomp($tipAmount, (string)(self::MAXIMUM_CARD_DONATION), 2) === 1) {
            throw new \UnexpectedValueException(sprintf(
                'Tip amount must not exceed %d %s',
                $max,
                $this->currencyCode,
            ));
        }

        if (bccomp($tipAmount, '0', 2) === -1) {
            throw new \UnexpectedValueException('Tip amount must not be negative');
        }

        $this->tipAmount = $tipAmount;
    }

    public function hasTipGiftAid(): ?bool
    {
        return $this->tipGiftAid;
    }

    public function setTipGiftAid(?bool $tipGiftAid): void
    {
        $this->tipGiftAid = $tipGiftAid;
    }

    private function getDonorHomeAddressLine1(): ?string
    {
        return $this->donorHomeAddressLine1;
    }

    private function getDonorHomePostcode(): ?string
    {
        return $this->donorHomePostcode;
    }

    public function setDonorHomePostcode(?string $donorHomePostcode): void
    {
        $this->donorHomePostcode = $donorHomePostcode;
    }

    /**
     * @return numeric-string
     */
    public function getCharityFeeVat(): string
    {
        return $this->charityFeeVat;
    }

    /**
     * @param numeric-string $charityFeeVat
     */
    public function setCharityFeeVat(string $charityFeeVat): void
    {
        $this->charityFeeVat = $charityFeeVat;
    }

    /**
     * @return int      The amount of the total donation, in cents/pence/..., which is to be excluded
     *                  from payout to the charity. This is the sum of
     *                  (a) any part of that amount which was a tip to the Big Give;
     *                  (b) fees on the remaining donation amount; and
     *                  (c) VAT on fees where applicable.
     *                  It does not include separately sourced funds like matching or
     *                  Gift Aid.
     */
    public function getAmountToDeductFractional(): int
    {
        $amountToDeduct = bcadd($this->getTipAmount(), $this->getCharityFeeGross(), 2);

        return (int) bcmul('100', $amountToDeduct, 2);
    }

    /**
     * @return int      The amount of the core donation, in pence/cents/..., which is to be paid out
     *                  to the charity. This is the amount paid by the donor minus
     *                  (a) any part of that amount which was a tip to the Big Give;
     *                  (b) fees on the remaining donation amount; and
     *                  (c) VAT on fees where applicable.
     *                  It does not include separately sourced funds like matching or
     *                  Gift Aid.
     *
     *                  This is just used in unit tests to validate we haven't broken anything now.
     *                  Note that because `getAmountToDeductFractional()` takes off the tip amount and
     *                  `getAmount()` relates to core amount, we must re-add the tip here to get a
     *                  correct answer.
     */
    public function getAmountForCharityFractional(): int
    {
        $amountFractional = (int) bcmul('100', $this->getAmount(), 2);
        return $amountFractional +
            $this->getTipAmountFractional() -
            $this->getAmountToDeductFractional();
    }

    /**
     * @return int  Full amount, including any tip or fee cover, in pence/cents/...
     */
    public function getAmountFractionalIncTip(): int
    {
        $coreAmountFractional = (int) bcmul('100', $this->getAmount(), 2);

        return $coreAmountFractional + $this->getTipAmountFractional();
    }

    /**
     * @return int  In e.g. pence/cents/...
     */
    public function getTipAmountFractional(): int
    {
        return (int) bcmul('100', $this->getTipAmount(), 2);
    }

    /**
     * @return string
     */
    public function getOriginalPspFee(): string
    {
        return $this->originalPspFee;
    }

    /**
     * @param numeric-string $originalPspFeeFractional
     * @return void
     */
    public function setOriginalPspFeeFractional(string $originalPspFeeFractional): void
    {
        $this->originalPspFee = bcdiv($originalPspFeeFractional, '100', 2);
    }

    /**
     * @return bool
     */
    public function hasTbgShouldProcessGiftAid(): ?bool
    {
        return $this->tbgShouldProcessGiftAid;
    }

    /**
     * @param bool $tbgShouldProcessGiftAid
     */
    public function setTbgShouldProcessGiftAid(?bool $tbgShouldProcessGiftAid): void
    {
        $this->tbgShouldProcessGiftAid = $tbgShouldProcessGiftAid;
    }

    public function setTbgGiftAidRequestQueuedAt(?DateTimeImmutable $tbgGiftAidRequestQueuedAt): void
    {
        $this->tbgGiftAidRequestQueuedAt = $tbgGiftAidRequestQueuedAt;
    }

    /**
     * @return DateTime|null
     */
    public function getTbgGiftAidRequestFailedAt(): ?DateTime
    {
        return $this->tbgGiftAidRequestFailedAt;
    }

    /**
     * @param DateTime|null $tbgGiftAidRequestFailedAt
     */
    public function setTbgGiftAidRequestFailedAt(?DateTime $tbgGiftAidRequestFailedAt): void
    {
        $this->tbgGiftAidRequestFailedAt = $tbgGiftAidRequestFailedAt;
    }

    public function getTbgGiftAidRequestConfirmedCompleteAt(): ?DateTime
    {
        return $this->tbgGiftAidRequestConfirmedCompleteAt;
    }

    /**
     * @param DateTime|null $tbgGiftAidRequestConfirmedCompleteAt
     */
    public function setTbgGiftAidRequestConfirmedCompleteAt(?DateTime $tbgGiftAidRequestConfirmedCompleteAt): void
    {
        $this->tbgGiftAidRequestConfirmedCompleteAt = $tbgGiftAidRequestConfirmedCompleteAt;
    }

    public function getTbgGiftAidRequestCorrelationId(): ?string
    {
        return $this->tbgGiftAidRequestCorrelationId;
    }

    /**
     * @param string|null $tbgGiftAidRequestCorrelationId
     */
    public function setTbgGiftAidRequestCorrelationId(?string $tbgGiftAidRequestCorrelationId): void
    {
        $this->tbgGiftAidRequestCorrelationId = $tbgGiftAidRequestCorrelationId;
    }

    public function getTbgGiftAidResponseDetail(): ?string
    {
        return $this->tbgGiftAidResponseDetail;
    }

    /**
     * @param string|null $tbgGiftAidResponseDetail
     */
    public function setTbgGiftAidResponseDetail(?string $tbgGiftAidResponseDetail): void
    {
        $this->tbgGiftAidResponseDetail = $tbgGiftAidResponseDetail;
    }

    public function getPspCustomerId(): ?StripeCustomerId
    {
        if ($this->pspCustomerId === null) {
            return null;
        };

        if ($this->psp !== 'stripe') {
            throw new \RuntimeException('Unexpected PSP');
        }

        return StripeCustomerId::of($this->pspCustomerId);
    }

    public function setPspCustomerId(?string $pspCustomerId): void
    {
        $this->pspCustomerId = $pspCustomerId;
    }

    public function getPaymentMethodType(): ?PaymentMethodType
    {
        return $this->paymentMethodType;
    }

    /**
     * We want to ensure each Payment Intent is set up to be settled a specific way, so
     * we get this from an on-create property of the Donation instead of using
     * `automatic_payment_methods`.
     * "card" includes wallets and is the method for the vast majority of donations.
     * "customer_balance" is used when a previous bank transfer means a donor already
     * has credits to use for platform charities, *and* for Big Give tips set up when
     * preparing to make a bank transfer. In the latter case we rely on
     * `payment_method_options` to allow the PI to be created even though there aren't
     * yet sufficient funds.
     */
    public function getStripeMethodProperties(): array
    {
        $properties = match ($this->paymentMethodType) {
            PaymentMethodType::CustomerBalance => [
                'payment_method_types' => ['customer_balance'],
            ],
            PaymentMethodType::Card => [
                // in this case we want to use the Stripe Payment Element, so we can't specify card explicitly, we
                // need to turn on automatic methods instead and let the element decide what methods to show.
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ]
            ],
        };

        if ($this->paymentMethodType === PaymentMethodType::CustomerBalance) {
            if ($this->currencyCode !== 'GBP') {
                throw new \UnexpectedValueException('Customer balance payments only supported for GBP');
            }

            $properties['payment_method_data'] = [
                'type' => PaymentMethodType::CustomerBalance->value,
            ];

            $properties['payment_method_options'] = [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => [
                        'type' => 'gb_bank_transfer',
                    ],
                ],
            ];
        }

        return $properties;
    }

    /**
     * This isn't supported for "customer_balance" Payment Intents, and is also not
     * really needed for them because the fee is fixed at the lowest level and there
     * is no new donor bank transaction, so no statement ref to consider.
     *
     * An important side effect to keep in mind is that this means payout timing for
     * donations funded by bank transfer / customer balance is dictated by the platform
     * (Big Give) Stripe settings, *not* those of the receiving charity's connected
     * account. This means we cannot currently add a delay, so depending on the day of the
     * week it's received, a donation could be paid out to the charity almost immediately.
     * This may necessitate us having a different refund policy for donations via credit –
     * to be discussed further in early/mid 2023.
     *
     * @link https://stripe.com/docs/payments/connected-accounts
     * @link https://stripe.com/docs/connect/destination-charges#settlement-merchant
     */
    public function getStripeOnBehalfOfProperties(): array
    {
        if ($this->paymentMethodType === PaymentMethodType::Card) {
            return ['on_behalf_of' => $this->getCampaign()->getCharity()->getStripeAccountId()];
        }

        return [];
    }

    /**
     * Sidestep "`setup_future_usage` cannot be used with one or more of the values you
     * specified in `payment_method_types`. Please remove `setup_future_usage` or
     * remove these types from `payment_method_types`: ["customer_balance"]".
     */
    public function supportsSavingPaymentMethod(): bool
    {
        return $this->paymentMethodType === PaymentMethodType::Card;
    }

    public function getDonorFullName(): ?string
    {
        $firstName = $this->getDonorFirstName();
        $lastName = $this->getDonorLastName();
        if ($firstName === null && $lastName === null) {
            return null;
        }

        return trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
    }

    public function hasEnoughDataForSalesforce(): bool
    {
        $firstName = $this->getDonorFirstName();
        $lastName = $this->getDonorLastName();

        return is_string($firstName) && $firstName !== '' && is_string($lastName) && $lastName !== '';
    }

    public function toClaimBotModel(): Messages\Donation
    {
        $lastName = $this->donorLastName;
        $firstName = $this->donorFirstName;
        $collectedAt = $this->getCollectedAt();
        if ($lastName === null) {
            throw new \Exception("Missing donor last name; cannot send donation to claimbot");
        }
        if ($firstName === null) {
            throw new \Exception("Missing donor first name; cannot send donation to claimbot");
        }
        if ($collectedAt === null) {
            throw new \Exception("Missing donor collected date; cannot send donation to claimbot");
        }

        $donationMessage = new Messages\Donation();
        $donationMessage->id = $this->uuid->toString();
        $donationMessage->donation_date = $collectedAt->format('Y-m-d');
        $donationMessage->title = '';
        $donationMessage->first_name = $firstName;
        $donationMessage->last_name = $lastName;

        $donationMessage->overseas = $this->donorHomePostcode === self::OVERSEAS;
        $donationMessage->postcode = $donationMessage->overseas ? '' : ($this->donorHomePostcode ?? '');

        $donationMessage->house_no = '';

        // MAT-192 will cover passing and storing this separately. For now, a pattern match should
        // give reasonable 'house number' values.
        if ($this->donorHomeAddressLine1 !== null) {
            $houseNumber = preg_replace('/^([0-9a-z-]+).*$/i', '$1', trim($this->donorHomeAddressLine1));
            \assert($houseNumber !== null);

            $donationMessage->house_no = $houseNumber;

            // In any case where this doesn't produce a result, just send the full first 40 characters
            // of the home address. This is also HMRC's requested value in this property for overseas
            // donations.
            if ($donationMessage->house_no === '' || $donationMessage->overseas) {
                $donationMessage->house_no = trim($this->donorHomeAddressLine1);
            }

            // Regardless of which source we used and if we are aiming for a number or a full
            // address, it should be truncated at 40 characters.
            $donationMessage->house_no = mb_substr($donationMessage->house_no, 0, 40);
        }

        $donationMessage->amount = (float) $this->amount;

        $donationMessage->org_hmrc_ref = $this->getCampaign()->getCharity()->getHmrcReferenceNumber() ?? '';
        $donationMessage->org_name = $this->getCampaign()->getCharity()->getName();
        $donationMessage->org_regulator = $this->getCampaign()->getCharity()->getRegulator();
        $donationMessage->org_regulator_number = $this->getCampaign()->getCharity()->getRegulatorNumber();

        return $donationMessage;
    }

    /**
     * Make text fit in limited and possibly-required SF fields
     *
     * @param string|null $text
     * @param bool $required
     * @return string|null
     */
    private function makeSalesforceSafe(?string $text, bool $required): ?string
    {
        if ($text === null || trim($text) === '') {
            if ($required) {
                return 'N/A';
            }

            return null;
        }

        return mb_substr($text, 0, 40);
    }

    public function recordRefundAt(\DateTimeImmutable $refundDate): void
    {
        if ($this->donationStatus === DonationStatus::Refunded) {
            return;
        }
        $this->donationStatus = DonationStatus::Refunded;
        $this->refundedAt = $refundDate;
    }

    /**
     * Sets tip amount to zero and records the refund date. Refund amount must match tip amount.
     */
    public function setTipRefunded(\DateTimeImmutable $datetime, Money $amountRefunded): void
    {
        $this->refundedAt = $datetime;
        Assertion::nullOrEq(
            $this->totalPaidByDonor,
            (string)($this->getAmountFractionalIncTip() / 100)
        );

        Assertion::true(
            $amountRefunded->equalsIgnoringCurrency($this->tipAmount),
            'Amount Refunded should equal tip amount'
        );

        if ($this->totalPaidByDonor !== null) {
            $this->totalPaidByDonor = bcsub($this->totalPaidByDonor, $this->tipAmount, 2);
        }

        $this->setTipAmount('0.00');
        $this->tipRefundAmount = $amountRefunded->toNumericString();
    }

    /**
     * Updates status to {@see DonationStatus::Cancelled}. Note that in most cases you will need to do more than just update the status,
     * so consider calling {@see DonationService::cancel()} rather than this directly.
     */
    public function cancel(): void
    {
        if (
            !in_array(
                $this->donationStatus,
                [
                    DonationStatus::Pending,
                    DonationStatus::PreAuthorized,
                    DonationStatus::Cancelled,
                    DonationStatus::Collected, // doesn't really make sense to cancel a collected donation but we have
                                               // existing unit tests doing that, not changing now.
                ],
                true
            )
        ) {
            throw new \UnexpectedValueException("Cannot cancel {$this->donationStatus->value} donation");
        }

        $this->donationStatus = DonationStatus::Cancelled;
    }

    /**
     * Updates a donation to set the appropriate fees. If card details are null then we assume for now that a card with
     * the lowest possible fees will be used, and this should be called again with the details of the selected card
     * when confirming the payment.
     */
    public function deriveFees(?CardBrand $cardBrand, ?Country $cardCountry): void
    {
        $incursGiftAidFee = $this->hasGiftAid() && $this->hasTbgShouldProcessGiftAid();

        $fees = Calculator::calculate(
            $this->getPsp(),
            $cardBrand,
            $cardCountry,
            $this->getAmount(),
            $this->currency()->isoCode(),
            $incursGiftAidFee,
        );

        $this->setCharityFee($fees->coreFee);
        $this->setCharityFeeVat($fees->feeVat);
    }

    public function collectFromStripeCharge(
        string $chargeId,
        int $totalPaidFractional,
        string $transferId,
        ?CardBrand $cardBrand,
        ?Country $cardCountry,
        string $originalFeeFractional,
        int $chargeCreationTimestamp
    ): void {
        Assertion::eq(is_null($cardBrand), is_null($cardCountry));
        Assertion::numeric($originalFeeFractional);
        Assertion::notEmpty($chargeId);
        Assertion::notEmpty($transferId);

        $this->chargeId = $chargeId;
        $this->transferId = $transferId;
        $this->donationStatus = DonationStatus::Collected;
        $this->collectedAt = (new \DateTimeImmutable("@$chargeCreationTimestamp"));
        $this->setOriginalPspFeeFractional($originalFeeFractional);

        $this->totalPaidByDonor = bcdiv((string)$totalPaidFractional, '100', 2);
    }

    /**
     * Updates a pending donation to reflect changes made in the donation form.
     */
    public function update(
        bool $giftAid,
        ?bool $tipGiftAid = null,
        ?string $donorHomeAddressLine1 = null,
        ?string $donorHomePostcode = null,
        ?DonorName $donorName = null,
        ?EmailAddress $donorEmailAddress = null,
        ?bool $tbgComms = false,
        ?bool $charityComms = false,
        ?bool $championComms = false,
        ?string $donorBillingPostcode = null,
    ): void {
        if ($this->donationStatus !== DonationStatus::Pending) {
            throw new \UnexpectedValueException("Update only allowed for pending donation");
        }

        if (trim($donorHomeAddressLine1 ?? '') === '') {
            $donorHomeAddressLine1 = null;
        }

        if (trim($donorBillingPostcode ?? '') === '') {
            $donorBillingPostcode = null;
        }

        if ($giftAid && $donorHomeAddressLine1 === null) {
            throw new \UnexpectedValueException("Cannot Claim Gift Aid Without Home Address");
        }

        try {
            $lazyAssertion = Assert::lazy();

            $lazyAssertion
                ->that($donorHomeAddressLine1, 'donorHomeAddressLine1')
                ->nullOr()->betweenLength(1, 255);

            /** postcode should either be a UK postcode or the word 'OVERSEAS' - either way length will be between 5 and
                8. Could consider adding a regex validation.
             * @see self::OVERSEAS
             */
            $lazyAssertion->that($donorHomePostcode, 'donorHomePostcode')->nullOr()->betweenLength(5, 8);

            // allow up to 15 chars to account for post / zip codes worldwide
            $lazyAssertion->that($donorBillingPostcode, 'donorBillingPostcode')->nullOr()->betweenLength(1, 15);

            $lazyAssertion->verifyNow();
        } catch (LazyAssertionException $e) {
            throw new \UnexpectedValueException($e->getMessage(), previous: $e);
        }

        $this->donorHomeAddressLine1 = $donorHomeAddressLine1;
        $this->donorBillingPostcode = $donorBillingPostcode;

        $this->setGiftAid($giftAid);
        $this->setTipGiftAid($tipGiftAid);
        $this->setTbgShouldProcessGiftAid($this->getCampaign()->getCharity()->isTbgClaimingGiftAid());
        $this->setDonorHomePostcode($donorHomePostcode);
        if ($donorName) {
            $this->setDonorName($donorName);
        }
        $this->setDonorEmailAddress($donorEmailAddress);
        $this->setTbgComms($tbgComms);
        $this->setCharityComms($charityComms);
        $this->setChampionComms($championComms);
    }

    /**
     * Checks the donation is ready to be confirmed if and when the donor is ready to pay - i.e. that all required
     * fields are filled in.
     *
     * @param DateTimeImmutable $at
     * @throws LazyAssertionException if not.
     *
     * This method returning true does *NOT* indicate that the donor has chosen to definitely donate - that must be
     * established based on other info (e.g. because they sent a confirmation request).
     */
    public function assertIsReadyToConfirm(\DateTimeImmutable $at): true
    {
        $this->assertionsForConfirmOrPreAuth()
            ->that($this->transactionId)->notNull('Missing Transaction ID')
            ->that($this->getCampaign()->isOpenForFinalising($at))
            ->verifyNow();

        return true;
    }

    /**
     * Authorises BG to collect this donation at the given date in the future.
     */
    public function preAuthorize(DateTimeImmutable $paymentDate): void
    {
        $this->assertionsForConfirmOrPreAuth()->verifyNow();
        $this->preAuthorizationDate = $paymentDate;
        $this->donationStatus = DonationStatus::PreAuthorized;
    }

    public function getPreAuthorizationDate(): ?\DateTimeImmutable
    {
        return $this->preAuthorizationDate;
    }

    /**
     * @return numeric-string|null The total the donor paid, either as recorded at the time or as we can calculate from
     * other info, in major currency units.
     *
     * Returns *original* charge amount even if now fully reversed, but only the net balance paid if there
     * was a tip-only refund.
     */
    public function getTotalPaidByDonor(): ?string
    {
        if (! $this->donationStatus->isSuccessful() && ! $this->donationStatus->isReversed()) {
            // incomplete donation, donor has not paid any amount yet.
            return null;
        }

        $total = $this->getAmountFractionalIncTip();
        $totalString = bcdiv((string)$total, '100', 2);

        if ($this->totalPaidByDonor !== null) {
            Assertion::eq($this->totalPaidByDonor, $totalString);
            // We need these to be equal to justify the fact that outside this if block we're returning $totalString
            // based on today's calculation and assuming it's equal to what we charged the donor at the time it was
            // confirmed.

            return $this->totalPaidByDonor;
        }

        return $totalString;
    }

    public function getMandateSequenceNumber(): ?DonationSequenceNumber
    {
        if ($this->mandateSequenceNumber === null) {
            return null;
        }
        return DonationSequenceNumber::of($this->mandateSequenceNumber);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getMandate(): ?RegularGivingMandate
    {
        return $this->mandate;
    }

    /**
     * @return array Representation of this donation suitable for creating a Stripe Payment intent with
     * @see \MatchBot\Client\Stripe::createPaymentIntent
     */
    public function createStripePaymentIntentPayload(): array
    {
        Assertion::same('stripe', $this->psp);

        /** @var array{metadata: array} $payload */
        $payload = [
            ...$this->getStripeMethodProperties(),
            ...$this->getStripeOnBehalfOfProperties(),
            'customer' => $this->getPspCustomerId()?->stripeCustomerId,
            // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
            // See https://stripe.com/docs/api/payment_intents/object
            'amount' => $this->getAmountFractionalIncTip(),
            'currency' => $this->currency()->isoCode(case: 'lower'),
            'description' => $this->getDescription(),
            'capture_method' => 'automatic', // 'automatic' was default in previous API versions,
            // default is now 'automatic_async'
            'metadata' => [
                /**
                 * Keys like comms opt ins are set only later. See the counterpart
                 * in {@see Update::addData()} too.
                 */
                'campaignId' => $this->getCampaign()->getSalesforceId(),
                'campaignName' => $this->getCampaign()->getCampaignName(),
                'charityId' => $this->getCampaign()->getCharity()->getSalesforceId(),
                'charityName' => $this->getCampaign()->getCharity()->getName(),
                'donationId' => $this->getUuid(),
                'environment' => getenv('APP_ENV'),
                'matchedAmount' => $this->getFundingWithdrawalTotal(),
                'stripeFeeRechargeGross' => $this->getCharityFeeGross(),
                'stripeFeeRechargeNet' => $this->getCharityFee(),
                'stripeFeeRechargeVat' => $this->getCharityFeeVat(),
                'tipAmount' => $this->getTipAmount(),
            ],
            'statement_descriptor' => $this->getCampaign()->getCharity()->getStatementDescriptor(),
            // See https://stripe.com/docs/connect/destination-charges#application-fee
            'application_fee_amount' => $this->getAmountToDeductFractional(),
            'transfer_data' => [
                'destination' => $this->getCampaign()->getCharity()->getStripeAccountId(),
            ],
        ];

        $mandate = $this->getMandate();
        $sequenceNumber = $this->getMandateSequenceNumber();
        if ($mandate !== null && $sequenceNumber !== null) {
            $payload['metadata']['mandateId'] = $mandate->getId();
            $payload['metadata']['mandateSequenceNumber'] = $sequenceNumber->number;
        }

        return $payload;
    }

    public function isFullyMatched(): bool
    {
        return bccomp($this->amount, $this->getFundingWithdrawalTotal(), 2) === 0;
    }

    public function getUuid(): UuidInterface
    {
        return $this->uuid;
    }

    /**
     * @return numeric-string The amount of gift aid claimable (or claimed) from HMRC to increase the gift aid value.
     *
     * Assumes that the Gift Aid percentage is unchanging and applies to all past donations. We need to
     * add more complexity if it does change.
     */
    public function getGiftAidValue(): string
    {
        if (! $this->giftAid) {
            return '0.00';
        }

        $amount = $this->amount;

        return self::donationAmountToGiftAidValue($amount);
    }

    /**
     * @return numeric-string
     */
    public function totalCharityValueAmount(): string
    {
        return Money::sum(
            ...array_map(Money::fromNumericStringGBP(...), [
                $this->amount,
                $this->getGiftAidValue(),
                $this->getFundingWithdrawalTotal()
            ])
        )->toNumericString();
    }

    /**
     * These assertions are required both for a donation to be confirmed and for it to be pre-authorized. They do
     * NOT include an assertion that the donation has a transaction ID, as that is only required for confirmation, not
     * for pre-auth.
     */
    private function assertionsForConfirmOrPreAuth(): \Assert\LazyAssertion
    {
        return Assert::lazy()
            ->that($this->donorFirstName, 'donorFirstName')->notNull('Missing Donor First Name')
            ->that($this->donorLastName, 'donorLastName')->notNull('Missing Donor Last Name')
            ->that($this->donorEmailAddress)->notNull('Missing Donor Email Address')
            ->that($this->donorCountryCode)->notNull('Missing Billing Country')
            ->that($this->donorBillingPostcode)->notNull('Missing Billing Postcode')
            ->that($this->tbgComms)->notNull('Missing tbgComms preference')
            ->that($this->charityComms)->notNull('Missing charityComms preference')
            ->that($this->donationStatus, 'donationStatus')
            ->that($this->donationStatus)->inArray(
                [DonationStatus::Pending, DonationStatus::PreAuthorized],
                "Donation status is '{$this->donationStatus->value}', must be " .
                "'Pending' or 'PreAuthorized' to confirm payment"
            );
    }

    /**
     * @throws RegularGivingDonationToOldToCollect if PreAuthDate is more than one month in the past.
     * @throws \Assert\AssertionFailedException if PreAuthDate is null or in the future.
     */
    public function checkPreAuthDateAllowsCollectionAt(\DateTimeImmutable $now): void
    {
        if (!($this->thisIsInDateRangeToConfirm($now))) {
            throw new RegularGivingDonationToOldToCollect(
                "Donation #{$this->getid()}} should have been collected at " . "
                {$this->getPreAuthorizationDate()?->format('Y-m-d')}, will not at this time",
            );
        }
    }

    public function thisIsInDateRangeToConfirm(DateTimeImmutable $now): bool
    {
        $preAuthorizationDate = $this->getPreAuthorizationDate();

        if ($preAuthorizationDate === null) {
            return true;
        }

        return
            $preAuthorizationDate <= $now &&
            $preAuthorizationDate->add(new \DateInterval('P1M')) >= $now;
    }

    public function getRefundedAt(): ?DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function getTipRefundAmount(): ?Money
    {
        if ($this->tipRefundAmount === null) {
            return null;
        }

        // @todo-multi-currency cheating a little by asserting that currency is GBP, since it is always likely to be.
        Assertion::same($this->currencyCode, 'GBP');
        return Money::fromNumericStringGBP($this->tipRefundAmount);
    }

    public function currency(): Currency
    {
        return Currency::fromIsoCode($this->currencyCode);
    }

    public function matchedAmount(): Money
    {
        return $this->getDonationStatus()->isSuccessful()
            ? $this->getFundingWithdrawalTotalAsObject()
            : Money::zero($this->currency());
    }

    public function isRegularGiving(): bool
    {
        return $this->mandate !== null;
    }

    /**
     * @throws CannotRemoveGiftAid
     */
    public function removeGiftAid(\DateTimeImmutable $at): void
    {
        // This function will be called via the Salesforce UI, so its safe to assume the donation has an SF ID by now.
        $salesforceId = $this->getSalesforceId();

        if ($this->tbgGiftAidRequestQueuedAt !== null) {
            $formattedQueuedDate = $this->tbgGiftAidRequestQueuedAt->format('Y-m-d H:i');

            throw new CannotRemoveGiftAid(
                "Cannot remove gift aid from donation {$salesforceId}, request already " .
                "queued to send to HMRC at {$formattedQueuedDate}"
            );
        }

        if ($this->giftAidRemovedAt !== null) {
            $formattedQueuedDate = $this->giftAidRemovedAt->format('Y-m-d H:i');

            // This function will be called via the Salesforce UI, so its safe to assume the donation has an SF ID by now.
            $salesforceId = $this->getSalesforceId();

            throw new CannotRemoveGiftAid(
                "Cannot remove gift aid from donation {$salesforceId}, gift aid " .
                "already removed at {$formattedQueuedDate}"
            );
        }

        if (!$this->giftAid && !$this->tipGiftAid) {
            throw new CannotRemoveGiftAid(
                "Cannot remove gift aid from donation {$salesforceId}, gift aid " .
                "was not requested by donor"
            );
        }

        $this->giftAidRemovedAt = $at;

        // todo - record the time that we're removing GA, and mabye also the the name/id of the person asking for it
        // to be removed, and the reason for removal?

        $this->giftAid = false;
        $this->tipGiftAid = false;
    }
}
