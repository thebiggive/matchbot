<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;

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

    #[ORM\OneToOne(inversedBy: 'campaignStatistics')]
    #[ORM\Id]
    private Campaign $campaign;

    #[ORM\Column(length: 18, unique: true)]
    protected string $campaignSalesforceId;

    /**
     * Total of core donation amounts and match funds, without Gift Aid.
     * Set on construct and updated when donations change.
     */
    #[ORM\Embedded(columnPrefix: 'amount_raised_')]
    private Money $amountRaised;

    /**
     * Total of core donation amounts, without match funds or Gift Aid.
     * Set on construct and updated when donations change.
     */
    #[ORM\Embedded(columnPrefix: 'donation_sum_')]
    private Money $donationSum;

    /** Set on construct only for now */
    #[ORM\Embedded(columnPrefix: 'match_funds_total_')]
    private Money $matchFundsTotal;

    /** Set on construct and updated when donations change */
    #[ORM\Embedded(columnPrefix: 'match_funds_used_')]
    private Money $matchFundsUsed;

    /**
     * @param Campaign $campaign
     * @param Money $amountRaised
     * @param Money $matchFundsUsed
     * @param Money $matchFundsTotal
     */
    public function __construct(
        Campaign $campaign,
        Money $donationSum,
        Money $amountRaised,
        Money $matchFundsUsed,
        Money $matchFundsTotal,
    ) {
        Assertion::greaterOrEqualThan($matchFundsTotal->toNumericString(), $matchFundsUsed->toNumericString());
        Assertion::eq(
            $amountRaised->toNumericString(),
            $donationSum->plus($matchFundsUsed)->toNumericString(),
        );

        $this->campaign = $campaign;
        $this->campaignSalesforceId = $campaign->getSalesforceId();
        $this->amountRaised = $amountRaised;
        $this->donationSum  = $donationSum;
        $this->matchFundsTotal = $matchFundsTotal;
        $this->matchFundsUsed = $matchFundsUsed;
        $this->createdNow();
    }

    public static function zeroPlaceholder(Campaign $campaign): self
    {
        $zero = Money::zero($campaign->getCurrency());

        return new self($campaign, $zero, $zero, $zero, $zero);
    }

    public function setAmountRaised(Money $amountRaised): void
    {
        $this->amountRaised = $amountRaised;
    }

    public function setMatchFundsUsed(Money $matchFundsUsed): void
    {
        $this->matchFundsUsed = $matchFundsUsed;
    }

    public function getDonationSum(): Money
    {
        return $this->donationSum;
    }

    public function getAmountRaised(): Money
    {
        return $this->amountRaised;
    }

    public function getMatchFundsUsed(): Money
    {
        return $this->matchFundsUsed;
    }

    public function getMatchFundsRemaining(): Money
    {
        return $this->matchFundsTotal->minus($this->matchFundsUsed);
    }

    public function getMatchFundsTotal(): Money
    {
        return $this->matchFundsTotal;
    }
}
