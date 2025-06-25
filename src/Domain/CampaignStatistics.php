<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Holds automatically calculated summary information from donations associated with a {@see Campaign}.
 * We keep copies so search ordering can stay performant, and to keep sync from Salesforce to charity
 * Campaigns one-way.
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
}
