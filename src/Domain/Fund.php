<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;

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
#[ORM\HasLifecycleCallbacks]
class Fund extends SalesforceReadProxy
{
    const string TYPE_CHAMPION_FUND = 'championFund';

    /**
     * Normal Pledges are used before {@see ChampionFund}s.
     * @see TopupPledge for the distinct type of pledge that is sometimes committed above a pledge target.
     */
    const string TYPE_PLEDGE = 'pledge';

    /** Top-up pledges represent commitments beyond a charity's pledge target (including when that target
    * is £0 because the campaign is 1:1 model) and are used *after* {@see ChampionFund}s.
    */
    const string TYPE_TOPUP_PLEDGE = 'topupPledge';

    const array types = [self::TYPE_CHAMPION_FUND, self::TYPE_PLEDGE, self::TYPE_CHAMPION_FUND];


    use TimestampsTrait;

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

    /**
     * @paslm-var self::TYPE_*
     */
    #[ORM\Column(type: 'string')]
    private string $fundType;

    /**
     * @psalm-param self::TYPE_* $type
     */
    final public function __construct(string $currencyCode, string $name, ?Salesforce18Id $salesforceId, string $type)
    {
        Assertion::inArray($type, self::types);
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->campaignFundings = new ArrayCollection();

        $this->currencyCode = $currencyCode;
        $this->name = $name;
        $this->salesforceId = $salesforceId?->value;
        $this->fundType = $type;
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
            'fundType' => $this->fundType,
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

    public function allocationOrder(): int
    {
        return match ($this->fundType) {
            'championFund' => 200,
            'pledge' => 100,
            'topupPledge' => 300,
        };
    }
}
