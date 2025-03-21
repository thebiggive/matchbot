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
 */
#[ORM\Table]
#[ORM\Entity(repositoryClass: FundRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Fund extends SalesforceReadProxy
{
    use TimestampsTrait;

    /**
     * FundType controlls allocation orders of campaign fundings. See docs on enum for details.
     */
    #[ORM\Column(type: 'string', enumType: FundType::class)]
    private FundType $fundType;

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

    public function __construct(string $currencyCode, string $name, ?Salesforce18Id $salesforceId, FundType $fundType)
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->campaignFundings = new ArrayCollection();

        $this->currencyCode = $currencyCode;
        $this->name = $name;
        $this->salesforceId = $salesforceId?->value;
        $this->fundType = $fundType;
    }

    /**
     * Change the type of an already-persisted Fund. Only allowed for pledges and only to go
     * from non-topup to topup mode.
     */
    public function changeType(FundType $newType): void
    {
        if (!$this->fundType->isPledge() || $newType !== FundType::TopupPledge) {
            // Refuse to make any change except to TopupPledge
            throw new \InvalidArgumentException('Only supported type change is Pledge (or already TopupPledge) funds to TopupPledge');
        }
        $this->fundType = $newType;
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
            'fundType' => $this->fundType->value,
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
        return $this->fundType->allocationOrder();
    }

    public function getFundType(): FundType
    {
        return $this->fundType;
    }
}
