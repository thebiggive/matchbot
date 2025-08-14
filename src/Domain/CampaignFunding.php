<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A specific allocation of money from a {@see Fund}, to one or more {@see Campaign}s.
 *
 * The Campaigns link is many-to-many, although the most common behaviour is to link to one Campaign.
 * Linking to multiple creates funding that is not ringfenced and is assigned on a 'first come first
 * served' basis to the Campaigns that receive donations first.
 *
 * Allocation order is currently set to a fixed value for each type of {@see Fund}, such that
 * Pledges are used before Champion funds, which are used before topup funds.
 * See {@see FundType::allocationOrder()}
 */
#[ORM\Table]
#[ORM\Index(name: 'available_fundings', columns: ['amountAvailable', 'id'])]
#[ORM\Entity(repositoryClass: CampaignFundingRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CampaignFunding extends Model
{
    use TimestampsTrait;

    /**
     * @var Collection<int, Campaign>
     */
    #[ORM\JoinTable(name: 'Campaign_CampaignFunding')]
    #[ORM\JoinColumn(name: 'campaignfunding_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'campaign_id', referencedColumnName: 'id')]
    #[ORM\ManyToMany(targetEntity: Campaign::class, inversedBy: 'campaignFundings')]
    protected Collection $campaigns;

    #[ORM\ManyToOne(targetEntity: Fund::class, cascade: ['persist'], inversedBy: 'campaignFundings')]
    #[ORM\JoinColumn(nullable: false)]
    protected Fund $fund;

    /**
     * @var string  ISO 4217 code for the currency in which all monetary values are denominated.
     */
    #[ORM\Column(length: 3)]
    protected string $currencyCode;

    /**
     * @var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see CampaignFunding::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $amount;

    /**
     * The amount of this funding allocation not already claimed. If you plan to allocate funds, always read this
     * with a PESSIMISTIC_WRITE lock and modify it in the same transaction you create a FundingWithdrawal.
     *
     * This is a less-realtime, eventually consistent copy of the information that we store in Redis.
     * Redis is the primary source of truth, for better performance and to avoid locking issues around fund allocation.
     *
     * @psalm-var numeric-string Use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see CampaignFunding::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $amountAvailable;

    /**
     * @var Collection<int, FundingWithdrawal>
     * We set cascade-remove for easier clearing of test data; Production should never remove a CampaignFunding via Doctrine.
     */
    #[ORM\OneToMany(mappedBy: 'campaignFunding', targetEntity: FundingWithdrawal::class, cascade: ['remove'])]
    private Collection $fundingWithdrawals;

    /**
     * @param numeric-string $amountAvailable
     * @param numeric-string $amount
     */
    public function __construct(
        Fund $fund,
        string $amount,
        string $amountAvailable,
    ) {
        $this->fund = $fund;
        $this->currencyCode = $fund->getCurrencyCode();
        $this->amount = $amount;
        $this->amountAvailable = $amountAvailable;
        $this->campaigns = new ArrayCollection();
        $this->createdNow();
    }

    public function __toString(): string
    {
        return "CampaignFunding, ID #{$this->id}, created {$this->createdAt->format('c')} " .
            "of fund SF ID {$this->fund->getSalesforceId()}";
    }

    /**
     * @return numeric-string
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @param numeric-string $amount
     */
    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    /**
     * @return string
     * @psalm-return numeric-string
     */
    public function getAmountAvailable(): string
    {
        return $this->amountAvailable;
    }

    /**
     * @psalm-param numeric-string $amountAvailable
     */
    public function setAmountAvailable(string $amountAvailable): void
    {
        $this->amountAvailable = $amountAvailable;
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
     * Currently only used in test
     * @return list<Campaign>
     */
    public function getCampaigns(): array
    {
        return array_values($this->campaigns->toArray());
    }

    public function getAllocationOrder(): int
    {
        return $this->getFund()->getAllocationOrder();
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
}
