<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;

const DISCRIMINATOR_MAP = [
    'championFund' => ChampionFund::class,
    'pledge' => Pledge::class,
    'topupPledge' => TopupPledge::class,
];

/**
 * Represents a commitment of match funds, i.e. a Champion Fund or Pledge. Because a Fund (most
 * typically a Champion Fund) can be split up and allocated to multiple Campaigns, the Fund in
 * MatchBot doesn't contain an allocated amount and is mostly a container for metadata to help understand
 * where any linked {@see CampaignFunding}s' money comes from.
 *
 * Concrete subclasses {@see ChampionFund} & {@see Pledge} are instantiated using Doctrine's
 * single table inheritance. The discriminator column is 'fundType' and the API field which determines
 * it originally, in {@see FundRepository::getNewFund()}, is 'type'.
 */
#[ORM\Table]
#[ORM\Entity(repositoryClass: FundRepository::class)]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'fundType', type: 'string')]
#[ORM\DiscriminatorMap([
    'unknownFund' => self::class,
    ...DISCRIMINATOR_MAP,
])]
#[ORM\HasLifecycleCallbacks]
abstract class Fund extends SalesforceReadProxy
{
    use TimestampsTrait;

    /** @var positive-int */
    public const int NORMAL_ALLOCATION_ORDER = 999;

    /**
     * We keep this public so `FundRepository` can do a reverse search to decide what to instantiate.
     * This way the Doctrine mapping (via attribute above), mapping to string for API push and mapping
     * *from* string for API pull, all live in one const array.
     *
     * @var array<string, class-string<Fund>>  Maps from API field to Fund subclass name.
     */
    public const array DISCRIMINATOR_MAP = DISCRIMINATOR_MAP;

    /**
     * @var string  ISO 4217 code for the currency used with this fund, and in which FundingWithdrawals are denominated.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected string $currencyCode;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;

    /**
     * @var Collection<int, CampaignFunding>
     */
    #[ORM\OneToMany(mappedBy: 'fund', targetEntity: CampaignFunding::class)]
    protected Collection $campaignFundings;

    final public function __construct(string $currencyCode, string $name, ?Salesforce18Id $salesforceId)
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->campaignFundings = new ArrayCollection();

        $this->currencyCode = $currencyCode;
        $this->name = $name;
        $this->salesforceId = $salesforceId?->value;
    }

    /**
     * @return 'championFund'|'pledge'|'topupPledge'|'unknownFund'
     */
    public function getDiscriminatorValue(): string
    {
        if ($value = array_search(static::class, self::DISCRIMINATOR_MAP, true)) {
            return $value;
        }

        // else no match in the known subclasses map
        return 'unknownFund';
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * Uses database copies of all data, not the Redis `Matching\Adapter`. Intended for use
     * after a campaign closes.
     *
     * @return array{totalAmount: Money, usedAmount: Money}
     */
    public function getAmounts(): array
    {
        $totalAmount = Money::fromPoundsGBP(0);
        $usedAmount = Money::fromPoundsGBP(0);

        foreach ($this->campaignFundings as $campaignFunding) {
            $thisAmount = Money::fromNumericStringGBP($campaignFunding->getAmount());
            $thisAmountAvailable = Money::fromNumericStringGBP($campaignFunding->getAmountAvailable());
            $thisAmountUsed = $thisAmount->minus($thisAmountAvailable);

            $totalAmount = $totalAmount->plus($thisAmount);
            $usedAmount = $usedAmount->plus($thisAmountUsed);
        }

        return [
            'totalAmount' => $totalAmount,
            'usedAmount' => $usedAmount,
        ];
    }

    /**
     * @return array{
     *     fundId: ?int,
     *     fundType: 'championFund'|'pledge'|'topupPledge'|'unknownFund',
     *     salesforceFundId: string,
     *     totalAmount: float, // used as Decimal in SF
     *     usedAmount: float, // used as Decimal in SF
     *     currencyCode: string
     * }
     */
    public function toAmountUsedUpdateModel(): array
    {
        $sfId = $this->getSalesforceId();
        Assertion::notNull($sfId); // Only updating existing SF fund objects supported.

        $amounts = $this->getAmounts();

        return [
            'currencyCode' => $amounts['totalAmount']->currency->isoCode(),
            'fundId' => $this->getId(),
            'fundType' => $this->getDiscriminatorValue(),
            'salesforceFundId' => $sfId,
            'totalAmount' => (float) $amounts['totalAmount']->toNumericString(),
            'usedAmount' => (float) $amounts['usedAmount']->toNumericString(),
        ];
    }

    /**
     * @param CampaignFunding $funding which must already refer to this Fund. The field on this class the
     * 'inverse' side of the relationship between the two in Doctrine, meaning that calling this function doesn't
     * actually affect what gets saved to the DB. Only the values of \MatchBot\Domain\CampaignFunding::$fund are
     * monitored by the ORM.
     */
    public function addCampaignFunding(CampaignFunding $funding): void
    {
        Assertion::same($funding->getFund(), $this);

        $this->campaignFundings->add($funding);
    }

    /**
     * @return positive-int
     */
    public function getAllocationOrder(): int
    {
        $order = $this::NORMAL_ALLOCATION_ORDER;
        \assert(is_int($order) && $order > 0);
        return $order;
    }
}
