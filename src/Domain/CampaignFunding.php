<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table]
#[ORM\Index(name: 'available_fundings', columns: ['amountAvailable', 'allocationOrder', 'id'])]
#[ORM\Entity(repositoryClass: CampaignFundingRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CampaignFunding extends Model
{
    use TimestampsTrait;

    /**
     * @var Campaign[]
     */
    #[ORM\JoinTable(name: 'Campaign_CampaignFunding')]
    #[ORM\JoinColumn(name: 'campaignfunding_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'campaign_id', referencedColumnName: 'id')]
    #[ORM\ManyToMany(targetEntity: Campaign::class)]
    protected $campaigns;

    /**
     * @var Fund
     */
    #[ORM\ManyToOne(targetEntity: Fund::class, cascade: ['persist'])]
    protected Fund $fund;

    /**
     * @var string  ISO 4217 code for the currency in which all monetary values are denominated.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected string $currencyCode;

    /**
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see CampaignFunding::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $amount;

    /**
     * The amount of this funding allocation not already claimed. If you plan to allocate funds, always read this
     * with a PESSIMISTIC_WRITE lock and modify it in the same transaction you create a FundingWithdrawal.
     *
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see CampaignFunding::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $amountAvailable;

    /**
     * @var int     Order of preference as a rank, i.e. lower numbers have their funds used first.
     */
    #[ORM\Column(type: 'integer')]
    protected int $allocationOrder;

    public function __construct()
    {
        $this->campaigns = new ArrayCollection();
    }

    public function __toString(): string
    {
        return "CampaignFunding, ID #{$this->id}, created {$this->createdAt->format('c')} of fund SF ID {$this->fund->getSalesforceId()}";
    }

    public function isShared(): bool
    {
        return (count($this->campaigns) > 1);
    }

    /**
     * @return string
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @param string $amount
     */
    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    /**
     * @return string
     */
    public function getAmountAvailable(): string
    {
        return $this->amountAvailable;
    }

    /**
     * @param string $amountAvailable
     */
    public function setAmountAvailable(string $amountAvailable): void
    {
        $this->amountAvailable = $amountAvailable;
    }

    /**
     * @param Fund $fund
     */
    public function setFund(Fund $fund): void
    {
        $this->fund = $fund;
    }

    /**
     * Add a Campaign to those for which this CampaignFunding is available, *if* not already linked.
     *
     * @param Campaign $campaign
     */
    public function addCampaign(Campaign $campaign): void
    {
        if ($this->campaigns->contains($campaign)) {
            return;
        }

        $this->campaigns->add($campaign);
    }

    /**
     * @param int $allocationOrder
     */
    public function setAllocationOrder(int $allocationOrder): void
    {
        $this->allocationOrder = $allocationOrder;
    }

    /**
     * @return Fund
     */
    public function getFund(): Fund
    {
        return $this->fund;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }
}
