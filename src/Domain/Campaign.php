<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Client\Campaign as CampaignClient;

/**
 * @psalm-import-type SFCampaignApiResponse from CampaignClient
 *
 * @psalm-suppress UnusedProperty - new properties to be used in MAT-405 campaign.parentTarget rendering.
 */
#[ORM\Table]
#[ORM\Index(name: 'end_date_and_is_matched', columns: ['endDate', 'isMatched'])]
#[ORM\Index(name: 'metaCampaignSlug', columns: ['metaCampaignSlug'])]
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Campaign extends SalesforceReadProxy
{
    use TimestampsTrait;

    /**
     * @var Charity
     */
    #[ORM\ManyToOne(targetEntity: Charity::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'charity_id', referencedColumnName: 'id', nullable: false)]
    protected Charity $charity;

    /**
     * @var Collection<int, CampaignFunding>
     * @psalm-suppress PossiblyUnusedProperty Used in Doctrine ORM mapping
     */
    #[ORM\ManyToMany(targetEntity: CampaignFunding::class, mappedBy: 'campaigns')]
    protected Collection $campaignFundings;

    /**
     * @var string  ISO 4217 code for the currency in which donations can be accepted and matching's organised.
     */
    #[ORM\Column(length: 3)]
    protected string $currencyCode;

    /**
     * Status as sent from SF API. Not currently used in matchbot but here for ad-hoc DB queries and
     * possible future use.
     *
     * Consider converting to enum or value object before using in any logic.
     *
     * @var 'Active' | 'Expired' | 'Preview' | null
     *
     * Default null because campaigns not recently updated in matchbot have not pulled this field from SF.
     */
    #[ORM\Column(length: 64, nullable: true, options: ['default' => null])]
    private ?string $status = null; // @phpstan-ignore doctrine.columnType

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;


    /**
     * Slug of the related metacampaign, if any. Will be output as is, and also used in joins etc.
     *
     * Would be neater to use either an SF ID or our DB numeric ID and possibly a doctrine based join,
     * but that would require having the metacampaign in the DB before we fetch the campaign from SF.
     *
     * For now using the slug instead to allow fetching in either order & filling this in on existing data while we
     * don't yet have metacampaigns in DB.
     *
     * Consider replacing with some sort of synthetic ID in future.
     */
    #[ORM\Column(length: 64, unique: false, nullable: true)]
    protected ?string $metaCampaignSlug;

    /**
     * Full data about this campaign as received from Salesforce. Not for use as-is in Matchbot domain logic but
     * may be used in ad-hoc queries, migrations, and perhaps for outputting to FE to provide compatibility with the SF
     * API. Charity data is ommitted here to avoid duplication with {@see Charity::$salesforceData}.
     * @var array<string, mixed>
     */
    #[ORM\Column(type: "json", nullable: false)]
    private array $salesforceData = [];

    /**
     * The first moment when donors should be able to make a donation, or a regular giving mandate
     **/
    #[ORM\Column(type: 'datetime')]
    protected DateTimeInterface $startDate;

    /**
     * The last moment when donors should be able to make an ad-hoc donation, or create a new
     * regular giving mandate.
     *
     * @see self::$regularGivingCollectionEnd
     * @var DateTimeInterface
     */
    #[ORM\Column(type: 'datetime')]
    protected DateTimeInterface $endDate;

    /**
     * @var bool    Whether the Campaign has any match funds
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $isMatched;

    /**
     * Dictates whether campaign is/will be ready to accept donations. Currently calculated in SF Apex code
     * based on status. A campaign may be ready but not yet open, in which case it will not accept donations right now.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $ready;

    /**
     * If true, FE will show a message that donations are currently unavailable for this campaign and searches
     * will exclude it. Very rarely set, available in case a campaign needs to be cancelled or paused quickly.
     *
     * In future, we may want the matchbot API to return a 404 for hidden campaigns.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $hidden;

    #[ORM\Column()]
    private bool $isRegularGiving;

    /**
     * Custom message from the charity to donors thanking them for donating. Used here for regular giving
     * confirmation emails, also used from SF for ad-hoc giving thanks pages and emails.
     */
    #[ORM\Column(length: 500, nullable: true, options: ['default' => null])]
    private ?string $thankYouMessage;

    /**
     * Date at which we want to stop collecting payments for this regular giving campaign. Must be null if
     * this is not regular giving, will also be null if this is regular giving and we plan to continue collecting
     * donations indefinitely.
     *
     * Creating new mandates may have stopped at an earlier date:
     * @see self::$endDate
     */
    #[ORM\Column(nullable: true)]
    protected ?\DateTimeImmutable $regularGivingCollectionEnd;

    #[ORM\Embedded(columnPrefix: 'total_funding_allocation_')]
    private Money $totalFundingAllocation;

    #[ORM\Embedded(columnPrefix: 'amount_pledged_')]
    private Money $amountPledged;

    #[ORM\Embedded(columnPrefix: 'total_fundraising_target_')]
    private Money $totalFundraisingTarget;

    /**
     * @param Money $totalFundraisingTarget
     * @param Salesforce18Id<Campaign> $sfId
     * @param \DateTimeImmutable|null $regularGivingCollectionEnd
     * @param 'Active'|'Expired'|'Preview'|null $status
     * @param bool $isRegularGiving
     * @param array<string,mixed> $rawData - data about the campaign as sent from Salesforce
     * */
    public function __construct(
        Salesforce18Id $sfId,
        ?string $metaCampaignSlug,
        Charity $charity,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        bool $isMatched,
        bool $ready,
        ?string $status,
        string $name,
        string $currencyCode,
        Money $totalFundingAllocation,
        Money $amountPledged,
        bool $isRegularGiving,
        ?\DateTimeImmutable $regularGivingCollectionEnd,
        Money $totalFundraisingTarget,
        ?string $thankYouMessage = null,
        array $rawData = [],
        bool $hidden = false,
    ) {
        $this->createdNow();
        $this->campaignFundings = new ArrayCollection();
        $this->charity = $charity;
        parent::setSalesforceId($sfId->value);

        $this->updateFromSfPull(
            currencyCode: $currencyCode,
            status: $status,
            endDate: $endDate,
            isMatched: $isMatched,
            name: $name,
            metaCampaignSlug: $metaCampaignSlug,
            startDate: $startDate,
            ready: $ready,
            isRegularGiving: $isRegularGiving,
            regularGivingCollectionEnd: $regularGivingCollectionEnd,
            thankYouMessage: $thankYouMessage,
            hidden: $hidden,
            totalFundingAllocation: $totalFundingAllocation,
            amountPledged: $amountPledged,
            totalFundraisingTarget: $totalFundraisingTarget,
            sfData: $rawData,
        );
    }

    /**
     * @param SFCampaignApiResponse $campaignData
     * @param Salesforce18Id<Campaign> $salesforceId
     * @param Charity $charity
     * @return Campaign
     * @throws \DateMalformedStringException
     */
    public static function fromSfCampaignData(array $campaignData, Salesforce18Id $salesforceId, Charity $charity, bool $fillInDefaultValues = false): self
    {
        $regularGivingCollectionEnd = $campaignData['regularGivingCollectionEnd'] ?? null;
        $regularGivingCollectionObject = $regularGivingCollectionEnd === null ?
            null : new \DateTimeImmutable($regularGivingCollectionEnd);

        $status = $campaignData['status'];
        $ready = $campaignData['ready'] ?? false;

        $startDate = $campaignData['startDate'];
        $endDate = $campaignData['endDate'];
        $title = $campaignData['title'];

        if (($status === null || $status === 'Expired') && $fillInDefaultValues) {
            // this campaign is not yet ready for public viewing so fill in some placeholder values to make it usable.
            // 1970 is effectively another form of null that's harder to insert by accident that actual null would be
            // if we allowed it  - we convert back to real null when rendering the campaign to an array.
            $startDate ??= '1970-01-01T00:00:00.000Z';
            $endDate ??= '1970-01-01T00:00:00.000Z';
            $title ??= 'Untitled campaign'; // can be null in source data for an expired campaign.
        } else {
            Assertion::notNull($startDate, 'Start date should not be null');
            Assertion::notNull($endDate, 'End date should not be null');
            Assertion::notNull($title);
        }

        Assertion::false(
            $campaignData['x_isMetaCampaign'] ?? false,
            'Cannot create Charity Campaign using meta campaign data'
        );

        $currency = Currency::fromIsoCode($campaignData['currencyCode']);

        return new self(
            sfId: $salesforceId,
            metaCampaignSlug: $campaignData['parentRef'],
            charity: $charity,
            startDate: new \DateTimeImmutable($startDate),
            endDate: new \DateTimeImmutable($endDate),
            isMatched: $campaignData['isMatched'],
            ready: $ready,
            status: $status,
            name: $title,
            currencyCode: $currency->isoCode(),
            totalFundingAllocation: Money::fromPence((int)(100.0 * ($campaignData['totalFundingAllocation'] ?? 0.0)), $currency),
            amountPledged: Money::fromPence((int)(100.0 * ($campaignData['amountPledged'] ?? 0.0)), $currency),
            isRegularGiving: $campaignData['isRegularGiving'] ?? false,
            regularGivingCollectionEnd: $regularGivingCollectionObject,
            thankYouMessage: $campaignData['thankYouMessage'],
            rawData: $campaignData,
            hidden: $campaignData['hidden'],
            totalFundraisingTarget: Money::zero(),
        );
    }

    /**
     * Implemented only so this can be cast to string if required for logging etc - not for use in any business process.
     */
    public function __toString(): string
    {
        return "Campaign ID #{$this->id}, SFId: {$this->getSalesforceId()}";
    }

    /**
     * @deprecated
     */
    #[\Override]
    public function setSalesforceId(string $salesforceId): void
    {
        throw new \Exception("Campaign sf ID is set at creation time, doesn't need to be changed later");
    }

    /**
     * @return bool does this campaign accept one-off giving, i.e. non-regular giving.
     * @see self::isRegularGiving()
     */
    public function isOneOffGiving(): bool
    {
        return ! $this->isRegularGiving;
    }

    /**
     * @return bool does this campaign accept regular giving, i.e. can it have regular giving mandates.
     */
    public function isRegularGiving(): bool
    {
        return $this->isRegularGiving;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[ORM\PrePersist]
    public function prePersistCheck(PrePersistEventArgs $_args): void
    {
        try {
            // PHP doesn't have a much nicer way to check if a property is initialised because the maintainers think
            // all typed properties should be initialised by end of constructor so we shouldn't need to check.
            // https://externals.io/message/114607
            //
            // I previously tried enforcing this at the DB level but that migration wouldn't run in staging or reg
            // envrionments

            $_charity = $this->charity;
        } catch (\Error $e) { // @phpstan-ignore catch.neverThrown
            throw new \Exception(
                "Error on attempt to persist campaign #{$this->id}, sfID {$this->getSalesforceId()}: \n{$e}"
            );
        }
    }

    /**
     * @return bool
     */
    public function isMatched(): bool
    {
        return $this->isMatched;
    }

    /**
     * @param bool $isMatched
     */
    public function setIsMatched(bool $isMatched): void
    {
        $this->isMatched = $isMatched;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setStartDate(DateTimeInterface $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function setEndDate(DateTimeInterface $endDate): void
    {
        $this->endDate = $endDate;
    }

    /**
     * @return Charity
     */
    public function getCharity(): Charity
    {
        return $this->charity;
    }

    /**
     * @return string
     */
    public function getCampaignName(): string
    {
        return $this->name;
    }

    /**
     * Is the campaign open to accept donations at the given time?
     */
    public function isOpen(\DateTimeImmutable $at): bool
    {
        return $this->isOpenWithEffectiveEndDate(at: $at, effectiveEndDate: \DateTimeImmutable::createFromInterface($this->endDate));
    }

    /**
     * Is the campaign open to accept finalisation of donations or regular giving mandates. We allow them
     * some time to think and use the form after the donor loads it load it just before the end date.
     */
    public function isOpenForFinalising(\DateTimeImmutable $at): bool
    {
        $halfAnHour = new \DateInterval('PT30M');

        $delayedEndDate = \DateTimeImmutable::createFromInterface($this->endDate)
            ->add($halfAnHour);

        return $this->isOpenWithEffectiveEndDate(at: $at, effectiveEndDate: $delayedEndDate);
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getEndDate(): DateTimeInterface
    {
        return $this->endDate;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * @param Money $totalFundraisingTarget
     * @param array<string,mixed> $sfData
     * @param 'Active'|'Expired'|'Preview'|null $status
     */
    final public function updateFromSfPull(
        string $currencyCode,
        ?string $status,
        \DateTimeInterface $endDate,
        bool $isMatched,
        string $name,
        ?string $metaCampaignSlug,
        \DateTimeInterface $startDate,
        bool $ready,
        bool $isRegularGiving,
        ?\DateTimeImmutable $regularGivingCollectionEnd,
        ?string $thankYouMessage,
        bool $hidden,
        Money $totalFundingAllocation,
        Money $amountPledged,
        Money $totalFundraisingTarget,
        array $sfData,
    ): void {
        Assertion::lessOrEqualThan(
            $startDate,
            $endDate,
            "Campaign may not end before it starts {$this->getSalesforceId()}"
        );

        Assertion::eq($currencyCode, 'GBP', 'Only GBP currency supported at present');
        Assertion::nullOrRegex($status, "/^[A-Za-z]{2,30}$/");
        Assertion::betweenLength($name, 1, 255);
        Assertion::nullOrMaxLength($thankYouMessage, 500);
        Assertion::nullOrBetweenLength($metaCampaignSlug, 1, 64);
        Assertion::nullOrRegex($metaCampaignSlug, '/^[-A-Za-z0-9]+$/');

        if ($metaCampaignSlug !== null && \str_starts_with($metaCampaignSlug, 'a05')) {
            // needed because SF may send an ID if slug is not filled in - we don't want that in the matchbot DB.
            throw new \RuntimeException("$metaCampaignSlug appears to be an SF ID, should be a slug");
        }

        if (! $isRegularGiving) {
            Assertion::null(
                $regularGivingCollectionEnd,
                "Can't have a regular giving collection end date for non-regular campaign {$this->getSalesforceId()}"
            );
        }

        $this->currencyCode = $currencyCode;
        $this->endDate = $endDate;
        $this->isMatched = $isMatched;
        $this->name = $name;
        $this->metaCampaignSlug = $metaCampaignSlug;
        $this->startDate = $startDate;
        $this->ready = $ready;
        $this->status = $status;
        $this->thankYouMessage = $thankYouMessage;
        $this->isRegularGiving = $isRegularGiving;
        $this->regularGivingCollectionEnd = $regularGivingCollectionEnd;
        $this->hidden = $hidden;
        $this->totalFundingAllocation = $totalFundingAllocation;
        $this->amountPledged = $amountPledged;
        $this->totalFundraisingTarget = $totalFundraisingTarget;

        unset($sfData['charity']); // charity stores its own data, we don't need to keep a copy here.
        $this->salesforceData = $sfData;
    }

    /** @return  'Active' | 'Expired' | 'Preview' | null */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    #[\Override]
    public function getSalesforceId(): string
    {
        // salesforce ID is set in Campaign constructor, so should never be null.
        $salesforceId = parent::getSalesforceId();
        Assertion::string($salesforceId);

        return $salesforceId;
    }

    public function regularGivingCollectionIsEndedAt(\DateTimeImmutable $date): bool
    {
        return $this->regularGivingCollectionEnd !== null && $this->regularGivingCollectionEnd <= $date;
    }

    public function getRegularGivingCollectionEnd(): ?\DateTimeImmutable
    {
        return $this->regularGivingCollectionEnd;
    }

    public function getThankYouMessage(): ?string
    {
        return $this->thankYouMessage;
    }

    /**
     * @return Salesforce18Id<Charity>
     */
    public function getCharityId(): Salesforce18Id
    {
        return Salesforce18Id::ofCharity(
            $this->getCharity()->getSalesforceId()
        );
    }

    /**
     * @throws CampaignNotOpen
     * @throws WrongCampaignType
     */
    public function checkIsReadyToAcceptDonation(Donation $donation, \DateTimeImmutable $at): void
    {
        if (! $this->isRegularGiving() && !$this->isOpen($at)) {
            throw new CampaignNotOpen("Campaign {$this->getSalesforceId()} is not open");
        }

        if ($donation->getMandate() === null && $this->isRegularGiving()) {
            throw new WrongCampaignType(
                "Campaign {$this->getSalesforceId()} does not accept one-off giving (regular-giving only)"
            );
        }

        if ($donation->getMandate() !== null && $this->isOneOffGiving()) {
            throw new WrongCampaignType(
                "Campaign {$this->getSalesforceId()} does not accept regular giving (one-off only)"
            );
        }
    }

    private function isOpenWithEffectiveEndDate(\DateTimeImmutable $at, \DateTimeImmutable $effectiveEndDate): bool
    {
        return $this->ready && $this->startDate <= $at && $effectiveEndDate > $at;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->startDate);
    }

    /**
     * @return SFCampaignApiResponse
     *
     * Note suppressions - technically the type returned here is what SF returned in the past when the DB entry was
     * generated, which may be before this code was written. Use returned value cautiously.
     *
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function getSalesforceData(): array
    {
        return $this->salesforceData + ['charity' => $this->charity->getSalesforceData()];
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getMetaCampaignSlug(): ?MetaCampaignSlug
    {
        if ($this->metaCampaignSlug === null) {
            return null;
        }

        return MetaCampaignSlug::of($this->metaCampaignSlug);
    }

    public static function target(Campaign $campaign, ?MetaCampaign $metaCampaign): Money
    {
        if ($metaCampaign) {
            Assertion::eq($campaign->metaCampaignSlug, $metaCampaign->getSlug()->slug);
        }

        if ($metaCampaign && $metaCampaign->isEmergencyIMF()) {
            // Emergency IMF targets can currently assume a shared match pot, so use parent totals to calculate
            // target for Emergency IMFs&apos; children: double the total match funds available (or override if set)
            //because parents do not have total fund raising target set */

            return $metaCampaign->target();
        }

        // SF implementation uses `Type__c = 'Regular Campaign'` is the condition. We don't have a copy of
        // `Type__c` but I think the below is equivalent:
        if (! $campaign->isMatched) {
            return $campaign->totalFundraisingTarget;
        }

        return Money::sum($campaign->amountPledged, $campaign->totalFundingAllocation)->times(2);
    }
}
