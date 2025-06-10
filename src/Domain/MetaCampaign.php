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
    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column(length: 64, unique: true, nullable: false)]
    private string $slug;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
    private string $title;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
    private Currency $currency;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
    private string $status;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
    private bool $hidden;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column(nullable: true)]
    private ?string $summary;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column(nullable: true)]
    private ?string $bannerURI;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
    private \DateTimeImmutable $startDate;

    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
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
     * @psalm-suppress UnusedProperty - will be used soon
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
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
    private bool $isEmergencyIMF;


    /**
     * @param Money $totalAdjustment
     * @param Salesforce18Id<self> $salesforceId
     */
    public function __construct(
        MetaCampaignSlug $slug,
        Salesforce18Id $salesforceId,
        string $title,
        Currency $currency,
        string $status,
        bool $hidden,
        ?string $summary,
        ?UriInterface $bannerURI,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        bool $isRegularGiving,
        bool $isEmergencyIMF,
        Money $totalAdjustment,
    ) {
        Assertion::same($totalAdjustment->currency, $currency);

        $this->slug = $slug->slug;
        $this->setSalesforceId($salesforceId->value);
        $this->title = $title;
        $this->currency = $currency;
        $this->status = $status;
        $this->hidden = $hidden;
        $this->summary = $summary;
        $this->bannerURI = $bannerURI?->__toString();
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->isRegularGiving = $isRegularGiving;
        $this->isEmergencyIMF = $isEmergencyIMF;
        $this->totalAdjustment = $totalAdjustment;
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
            hidden: false,
            summary: '',
            bannerURI: null,
            startDate: new \DateTimeImmutable('1970-01-01'),
            endDate: new \DateTimeImmutable('1970-01-01'),
            isRegularGiving: false,
            isEmergencyIMF: false,
            totalAdjustment: Money::zero(),
        );

        $metaCampaign->updateFromSfData($data);

        return $metaCampaign;
    }


    /**
     * @param SFCampaignApiResponse $data
     */
    public function updateFromSfData(array $data): void
    {
        Assertion::true($data['x_isMetaCampaign'] ?? true);

        $status = $data['status'];

        Assertion::notNull($status);

        $bannerUri = $data['bannerUri'];
        $isRegularGiving = $data['isRegularGiving'] ?? null;
        Assertion::boolean($isRegularGiving);

        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $title = $data['title'];

        Assertion::notNull($startDate);
        Assertion::notNull($endDate);
        Assertion::notNull($title);

        $currency = Currency::fromIsoCode($data['currencyCode']);

        $totalAdjustment = (string)$data['totalAdjustment'];
        Assertion::numeric($totalAdjustment);

        $this->status = $status;
        $this->bannerURI = \is_string($bannerUri) ? (new Uri($bannerUri))->__toString() : null;
        $this->isRegularGiving = $isRegularGiving;
        $this->title = $title;
        $this->currency = $currency;
        $this->hidden = $data['hidden'];
        $this->summary = $data['summary'];
        $this->startDate = new \DateTimeImmutable($startDate);
        $this->endDate = new \DateTimeImmutable($endDate);
        $this->isEmergencyIMF = $data['isEmergencyIMF'] ?? false; // @todo MAT-405 : start sending isEmergencyIMF from SF and remove null coalesce here

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
}
