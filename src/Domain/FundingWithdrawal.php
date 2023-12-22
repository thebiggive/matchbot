<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table]
#[ORM\Entity(repositoryClass: FundingWithdrawalRepository::class)]
#[ORM\HasLifecycleCallbacks]
class FundingWithdrawal extends Model
{
    use TimestampsTrait;

    /**
     * @var Donation
     */
    #[ORM\ManyToOne(targetEntity: Donation::class, inversedBy: 'fundingWithdrawals', fetch: 'EAGER')]
    protected Donation $donation;

    /**
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $amount;

    /**
     * @var CampaignFunding|null
     */
    #[ORM\ManyToOne(targetEntity: CampaignFunding::class, fetch: 'EAGER')]
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
