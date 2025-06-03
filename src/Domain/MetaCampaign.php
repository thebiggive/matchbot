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
class MetaCampaign extends SalesforceReadProxy
{
    /**
     * @psalm-suppress UnusedProperty - will be used soon
     */
    #[ORM\Column()]
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
     */
    #[ORM\Column()]
    private bool $isEmergencyIMF;


    /**
     * @param Salesforce18Id<self> $salesforceId
     */
    private function __construct(
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
    ) {
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
    }

    /**
     * @param SFCampaignApiResponse $data
     * Constructs an instance using the data from SF API (shared with Charity Campaign) and the given slug
     *
     * Slug is not sent in SF response but that's OK as we can assume we already know the slug when calling this.
     */
    public static function fromSfCampaignData(MetaCampaignSlug $slug, array $data): self
    {
        Assertion::true($data['x_isMetaCampaign'] ?? true);

        $status = $data['status'];

        Assertion::notNull($status);

        $bannerUri = $data['bannerUri'];
        $isRegularGiving = $data['isRegularGiving'] ?? null;
        Assertion::boolean($isRegularGiving);

        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        Assertion::notNull($startDate);
        Assertion::notNull($endDate);

        return new self(
            slug: $slug,
            salesforceId: Salesforce18Id::ofMetaCampaign($data['id']),
            title: $data['title'],
            currency: Currency::fromIsoCode($data['currencyCode']),
            status: $status,
            hidden: $data['hidden'],
            summary: $data['summary'],
            bannerURI: \is_string($bannerUri) ? new Uri($bannerUri) : null,
            startDate: new \DateTimeImmutable($startDate),
            endDate: new \DateTimeImmutable($endDate),
            isRegularGiving: $isRegularGiving,
            isEmergencyIMF: $data['isEmergencyIMF'] ?? false, // @todo MAT-405 : start sending isEmergencyIMF from SF and remove null coalesce here
        );
    }
}
