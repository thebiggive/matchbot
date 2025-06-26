<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Holds automatically calculated summary information from donations associated with a {@see Campaign}.
 * We keep copies so search ordering can stay performant, and to keep sync from Salesforce to charity
 * Campaigns one-way.
 *
 * @psalm-suppress UnusedProperty Properties are used in DQL & for manual DB queries
 * @psalm-suppress PossiblyUnusedProperty
 */
#[ORM\Table]
#[ORM\Entity(
    repositoryClass: null // we construct our own repository
)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['amount_raised_amountInPence'], name: 'amount_raised_amountInPence')]
#[ORM\Index(columns: ['match_funds_used_amountInPence'], name: 'match_funds_used_amountInPence')]
class CampaignStatistics
{
    use TimestampsTrait;

    #[ORM\OneToOne]
    #[ORM\Id]
    private Campaign $campaign;

    #[ORM\Column(length: 18, unique: true)]
    protected string $campaignSalesforceId;

    #[ORM\Embedded(columnPrefix: 'amount_raised_')]
    private Money $amountRaised;

    #[ORM\Embedded(columnPrefix: 'match_funds_used_')]
    private Money $matchFundsUsed;

    public function __construct(Campaign $campaign, Money $amountRaised, Money $matchFundsUsed)
    {
        $this->campaign = $campaign;
        $this->campaignSalesforceId = $campaign->getSalesforceId();
        $this->amountRaised = $amountRaised;
        $this->matchFundsUsed = $matchFundsUsed;
        $this->createdNow();
    }

    public function setAmountRaised(Money $amountRaised): void
    {
        $this->amountRaised = $amountRaised;
    }

    public function setMatchFundsUsed(Money $matchFundsUsed): void
    {
        $this->matchFundsUsed = $matchFundsUsed;
    }
}
