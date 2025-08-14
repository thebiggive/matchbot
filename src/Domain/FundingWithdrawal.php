<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor Not requiring all props on construct for now.
 */
#[ORM\Table]
#[ORM\Entity(repositoryClass: FundingWithdrawalRepository::class)]
#[ORM\HasLifecycleCallbacks]
class FundingWithdrawal extends Model
{
    use TimestampsTrait;

    /**
     * @psalm-suppress PossiblyUnusedProperty - probably used in DB queries
     */
    #[ORM\ManyToOne(targetEntity: Donation::class, inversedBy: 'fundingWithdrawals', fetch: 'EAGER')]
    #[ORM\JoinColumn(nullable: false)]
    protected Donation $donation;

    /**
     * @var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $amount;

    /**
     * @var CampaignFunding
     * phpstan error below ignored, consider migrating DB to make column non-null if possible.
     */
    #[ORM\ManyToOne(targetEntity: CampaignFunding::class, fetch: 'EAGER', inversedBy: 'fundingWithdrawals')]
    private readonly CampaignFunding $campaignFunding; // @phpstan-ignore doctrine.associationType

    public function __construct(CampaignFunding $campaignFunding)
    {
        $this->campaignFunding = $campaignFunding;
    }

    /**
     * @param Donation $donation
     */
    public function setDonation(Donation $donation): void
    {
        $this->donation = $donation;
    }

    /**
     * @param numeric-string $amount
     */
    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    /**
     * @return numeric-string
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCampaignFunding(): CampaignFunding
    {
        return $this->campaignFunding;
    }
}
