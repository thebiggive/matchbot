<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use Laminas\Diactoros\Uri;
use MatchBot\Application\Assertion;
use MatchBot\Client;
use Psr\Http\Message\UriInterface;

/**
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 *
 */
#[ORM\Table]
#[ORM\Entity(
    repositoryClass: null // we construct our own repository
)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'slug', columns: ['slug'])]
#[ORM\Index(name: 'title', columns: ['title'])]
#[ORM\Index(name: 'status', columns: ['status'])]
#[ORM\Index(name: 'hidden', columns: ['hidden'])]
class MetaCampaign extends SalesforceReadProxy
{
    use TimestampsTrait;

    public const string STATUS_VIEW_CAMPAIGN = 'View campaign';
    #[ORM\Column(length: 64, unique: true, nullable: false)]
    private string $slug;

    #[ORM\Column()]
    private string $title;

    #[ORM\Column()]
    private Currency $currency;

    /**
     * @var string|null Copy of Campaign_Status__c in Salesforce
     */
    #[ORM\Column(nullable: true)]
    private ?string $status;


    /**
     * @var string|null Copy of Master_Campaign_Status__c in Salesforce
     */
    #[ORM\Column(nullable: true)]
    private ?string $masterCampaignStatus;

    #[ORM\Column()]
    private bool $hidden;

    #[ORM\Column(length: 1_000, nullable: true)]
    private ?string $summary;

    #[ORM\Column(nullable: true)]
    private ?string $bannerURI;

    #[ORM\Column()]
    private \DateTimeImmutable $startDate;

    #[ORM\Column()]
    private \DateTimeImmutable $endDate;

    /**
     * CCampaign__c.Total_Adjustment__c from Salesforce - Adjustment amount (positive to add to the displayed grand
     * total) for a Master Campaign
     *
     * Total of any "offline" payments which need to be included in the campaign e.g. Award Payments, Offline Payments,
     * Gift Aid on Pledge Assumptions.
     *
     * @var Money
     */
    #[ORM\Embedded(columnPrefix: 'total_adjustment_')]
    private Money $totalAdjustment;

    /**
     * All associated charity campaigns must match this, accepting either regular or ad-hoc
     * donations, not a mixture.
     *
     * If true also implies that the charity campaigns will share funds - see doc for
     * {@see self::$isEmergencyIMF}
     *
     */
    #[ORM\Column()]
    private bool $isRegularGiving;

    /**
     * Is this an emergency campaign with shared, un-ringfenced champion funds?
     *
     * This is needed alongside isRegularGiving for calculating match funds available for
     * associated charity campaigns, as well as to render `usesSharedFunds` and
     * `parentUsesSharedFunds` when we render metacampaigns and charity campaigns
     * respectively to FE.
     *
     * {@see self::$isRegularGiving}
     *
     */
    #[ORM\Column()]
    private bool $isEmergencyIMF;

    #[ORM\Embedded(columnPrefix: 'imf_campaign_target_override_')]
    private Money $imfCampaignTargetOverride;

    /**
     * @param Salesforce18Id<self> $salesforceId
     *
     */
    #[ORM\Embedded(columnPrefix: 'match_funds_total_')]
    private Money $matchFundsTotal;

    /**
     * Ientifies the metacampaign as part of a series or type of campaigns, often with one member per year, e.g.
     * Christmas Challenge, Earth Raise etc. Will be used for setting colours, in future may also be used for homepage highlight cards and
     * perhaps other features.
     */
    #[ORM\Column(nullable: true)]
    private ?CampaignFamily $campaignFamily;

    /**
     * @param Salesforce18Id<self> $salesforceId
     */
    public function __construct(
        MetaCampaignSlug $slug,
        Salesforce18Id $salesforceId,
        string $title,
        Currency $currency,
        string $status,
        string $masterCampaignStatus,
        bool $hidden,
        ?string $summary,
        ?UriInterface $bannerURI,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        bool $isRegularGiving,
        bool $isEmergencyIMF,
        Money $totalAdjustment,
        Money $imfCampaignTargetOverride,
        Money $matchFundsTotal,
        ?CampaignFamily $campaignFamily,
    ) {
        Assertion::same($totalAdjustment->currency, $currency);

        $this->createdNow();

        $this->slug = $slug->slug;
        $this->setSalesforceId($salesforceId->value);
        $this->title = $title;
        $this->currency = $currency;
        $this->status = $status;
        $this->masterCampaignStatus = $masterCampaignStatus;
        $this->hidden = $hidden;
        $this->summary = $summary;
        $this->bannerURI = $bannerURI?->__toString();
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->isRegularGiving = $isRegularGiving;
        $this->isEmergencyIMF = $isEmergencyIMF;
        $this->totalAdjustment = $totalAdjustment;
        $this->imfCampaignTargetOverride = $imfCampaignTargetOverride;
        $this->matchFundsTotal = $matchFundsTotal;
        $this->campaignFamily = $campaignFamily;
    }

    /**
     * @param SFCampaignApiResponse $data
     * Constructs an instance using the data from SF API (shared with Charity Campaign) and the given slug
     *
     * Slug is not sent in SF response but that's OK as we can assume we already know the slug when calling this.
     */
    public static function fromSfCampaignData(MetaCampaignSlug $slug, array $data): self
    {
        // other than salesforceId which should never change just filling in all placeholder
        // values here for a microsecond - will be replaced in the call to updateFromSfData.
        // I'm sure there's a more elegant way to do this but at least this should look OK
        // from outside the class.
        $metaCampaign = new self(
            slug: $slug,
            salesforceId: Salesforce18Id::ofMetaCampaign($data['id']),
            title: '',
            currency: Currency::GBP,
            status: '',
            masterCampaignStatus: '',
            hidden: false,
            summary: '',
            bannerURI: null,
            startDate: new \DateTimeImmutable('1970-01-01'),
            endDate: new \DateTimeImmutable('1970-01-01'),
            isRegularGiving: false,
            isEmergencyIMF: false,
            totalAdjustment: Money::zero(),
            imfCampaignTargetOverride: Money::zero(),
            matchFundsTotal: Money::zero(),
            campaignFamily: CampaignFamily::from($data['campaignFamily']),
        );

        $metaCampaign->updateFromSfData($data);

        return $metaCampaign;
    }


    /**
     * @param SFCampaignApiResponse $data
     */
    public function updateFromSfData(array $data): void
    {
        Assertion::true($data['isMetaCampaign'] ?? true);

        $bannerUri = $data['bannerUri'];
        $isRegularGiving = $data['isRegularGiving'] ?? null;
        Assertion::boolean($isRegularGiving);

        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $title = $data['title'];

        // status may be null for now.
        $status = $data['campaignStatus'] ?? null;
        $masterCampaignStatus = $data['masterCampaignStatus'] ?? null;

        Assertion::notNull($startDate);
        Assertion::notNull($endDate);
        Assertion::notNull($title);

        $currency = Currency::fromIsoCode($data['currencyCode']);

        $totalAdjustment = (string)($data['totalAdjustment'] ?? '0.00');
        /** @psalm-suppress TypeDoesNotContainType */
        if ($totalAdjustment === '') {
            $totalAdjustment = '0.0';
        }
        Assertion::numeric($totalAdjustment);

        $this->status = $status;
        $this->masterCampaignStatus = $masterCampaignStatus;
        $this->bannerURI = \is_string($bannerUri) ? (new Uri($bannerUri))->__toString() : null;
        $this->isRegularGiving = $isRegularGiving;
        $this->title = $title;
        $this->currency = $currency;
        $this->hidden = $data['hidden'];
        $this->summary = $data['summary'];
        $this->startDate = new \DateTimeImmutable($startDate);
        $this->endDate = new \DateTimeImmutable($endDate);
        $this->isEmergencyIMF = $data['isEmergencyIMF'];

        $this->imfCampaignTargetOverride = Money::fromPence((int) (100.0 * ($data['imfCampaignTargetOverride'] ?? 0.0)), $currency);
        $this->matchFundsTotal = Money::fromPence((int) (100.0 * ($data['totalMatchedFundsAvailable'] ?? 0.0)), $currency);

        $this->totalAdjustment = Money::fromNumericString($totalAdjustment, $currency);
    }

    /**
     * Returns true if the campaigns within this meta-campaign should have access to a shared match funding pot, rather than
     * individual pots.
     */
    public function usesSharedFunds(): bool
    {
        return $this->isRegularGiving || $this->isEmergencyIMF;
    }

    public function getSlug(): MetaCampaignSlug
    {
        return MetaCampaignSlug::of($this->slug);
    }

    public function getTotalAdjustment(): Money
    {
        return $this->totalAdjustment;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getStatusAt(\DateTimeImmutable $date): ?string
    {
        if ($this->masterCampaignStatus !== self::STATUS_VIEW_CAMPAIGN) {
            return $this->status;
        }

        if ($date < $this->startDate) {
            return 'Preview';
        }

        if ($date > $this->endDate) {
            return 'Expired';
        }

        return 'Active';
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getBannerUri(): ?UriInterface
    {
        if ($this->bannerURI === null) {
            return null;
        }

        return new Uri($this->bannerURI);
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getMatchFundsTotal(): Money
    {
        return $this->matchFundsTotal;
    }

    public function target(): Money
    {
        // logic below originally ported from Campaign_Target__c in SF.
        if ($this->imfCampaignTargetOverride->isStrictlyPositive()) {
            return $this->imfCampaignTargetOverride;
        }

        return $this->matchFundsTotal->times(2);
    }

    public function isEmergencyIMF(): bool
    {
        return $this->isEmergencyIMF;
    }

    public function getFamily(): ?CampaignFamily
    {
        return $this->campaignFamily;
    }

    public function isOpen(\DateTimeImmutable $at): bool
    {
        return $at >= $this->startDate && $at <= $this->endDate;
    }
}
