<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="FundingWithdrawalRepository")
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
class FundingWithdrawal extends Model
{
    use TimestampsTrait;

    /**
     * @ORM\ManyToOne(targetEntity="Donation", inversedBy="fundingWithdrawals", fetch="EAGER")
     * @var Donation
     */
    protected Donation $donation;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected string $amount;

    /**
     * @ORM\ManyToOne(targetEntity="CampaignFunding", fetch="EAGER")
     * @var CampaignFunding|null
     */
    protected ?CampaignFunding $campaignFunding = null;

    /**
     * @param Donation $donation
     */
    public function setDonation(Donation $donation): void
    {
        $this->donation = $donation;
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
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @param CampaignFunding $campaignFunding
     */
    public function setCampaignFunding(CampaignFunding $campaignFunding): void
    {
        $this->campaignFunding = $campaignFunding;
    }

    public function getCampaignFunding(): ?CampaignFunding
    {
        return $this->campaignFunding;
    }
}
