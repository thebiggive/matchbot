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
#[ORM\Index(columns: ['lastCheck'], name: 'lastCheck')]
class CampaignStatistics
{
    use TimestampsTrait;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheck = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRealUpdate = null;

    #[ORM\OneToOne(inversedBy: 'campaignStatistics', fetch: 'EAGER')]
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

    /** Set on construct and updated when donations change. */
    #[ORM\Embedded(columnPrefix: 'match_funds_used_')]
    private Money $matchFundsUsed;

    /** Set on construct and updated when donations change. */
    #[ORM\Embedded(columnPrefix: 'match_funds_remaining_')]
    private Money $matchFundsRemaining;

    /**
     * Set on construct and when donations change. Uses {@see Campaign::$totalFundraisingTarget} on each update.
     * It's set to zero of the Campaign currency when the target is met, which also leads search to exclude the
     * Campaign when sorting by distance ascending.
     */
    #[ORM\Embedded(columnPrefix: 'distance_to_target_')]
    private Money $distanceToTarget;

    /**
     * @param Campaign $campaign
     * @param Money $amountRaised
     * @param Money $matchFundsUsed
     * @param Money $matchFundsTotal
     *
     * $amountRaised must be equal to $matchFundsUsed + $donationSum
     */
    public function __construct(
        \DateTimeImmutable $at,
        Campaign $campaign,
        Money $donationSum,
        Money $amountRaised,
        Money $matchFundsUsed,
        Money $matchFundsTotal,
    ) {
        $this->createdNow();
        $this->campaign = $campaign;
        $this->campaignSalesforceId = $campaign->getSalesforceId();

        $this->setTotals(
            at: $at,
            donationSum: $donationSum,
            amountRaised: $amountRaised,
            matchFundsUsed: $matchFundsUsed,
            matchFundsTotal: $matchFundsTotal,
            alwaysConsiderChanged: true,
        );
    }

    public static function zeroPlaceholder(Campaign $campaign, \DateTimeImmutable $at): self
    {
        $zero = Money::zero($campaign->getCurrency());

        return new self($at, $campaign, $zero, $zero, $zero, $zero);
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
        return $this->matchFundsRemaining;
    }

    public function getMatchFundsTotal(): Money
    {
        return $this->matchFundsTotal;
    }

    public function getDistanceToTarget(): Money
    {
        return $this->distanceToTarget;
    }

    /**
     * We manually set $lastCheck and $lastRealUpdate, since we need the former to avoid wasting resources and
     * changing that will cause lifecycle hooks to change $updatedAt.
     *
     * @param bool $alwaysConsiderChanged Hacky prop for now to avoid sa & runtime confusion about uninitialised
     *                                    props. Constructor sets true, other callers false.
     * @return bool Whether anything changed vs. the previously persisted stats.
     */
    final public function setTotals(
        \DateTimeImmutable $at,
        Money $donationSum,
        Money $amountRaised,
        Money $matchFundsUsed,
        Money $matchFundsTotal,
        bool $alwaysConsiderChanged,
    ): bool {
        Assertion::greaterOrEqualThan(
            $matchFundsTotal->toNumericString(),
            $matchFundsUsed->toNumericString(),
            'Match funds total must be greater than or equal to match funds used',
        );
        Assertion::eq(
            $amountRaised->toNumericString(),
            $donationSum->plus($matchFundsUsed)->toNumericString(),
            'Amount raised must equal donation sum plus match funds used',
        );

        /** @var ?CampaignStatistics $previousStats */
        $previousStats = null;
        if (!$alwaysConsiderChanged) {
            $previousStats = clone $this;
        }

        $this->amountRaised = $amountRaised;
        $this->donationSum = $donationSum;
        $this->matchFundsUsed = $matchFundsUsed;
        $this->matchFundsTotal = $matchFundsTotal;
        $this->matchFundsRemaining = $matchFundsTotal->minus($matchFundsUsed);

        $target = $this->campaign->getTotalFundraisingTarget();
        $this->distanceToTarget = $target->lessThan($amountRaised)
            ? Money::zero($this->campaign->getCurrency())
            : $target->minus($amountRaised);

        $didRealUpdate = (
            $alwaysConsiderChanged
            || $previousStats?->getAmountRaised() != $amountRaised
            || $previousStats?->getDonationSum() != $donationSum
            || $previousStats?->getMatchFundsUsed() != $matchFundsUsed
            || $previousStats?->getMatchFundsTotal() != $matchFundsTotal
            || $previousStats?->getMatchFundsRemaining() != $this->matchFundsRemaining
            || $previousStats?->getDistanceToTarget() != $this->distanceToTarget
        );

        $this->lastCheck = $at;
        if (!$didRealUpdate) {
            return false;
        }

        $this->lastRealUpdate = $at;

        return true;
    }
}
