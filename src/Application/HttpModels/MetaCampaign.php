<?php

namespace MatchBot\Application\HttpModels;

/**
 * Representation of a Meta Campaign to serialise and send to FE. Should be assignable to TS MetaCampaign model
 * (which we may want to introduce automatic checks for in future) and match the MetaCampaign as served from SF.
 *
 * @psalm-suppress PossiblyUnusedProperty - instances will be seralised and used in Front End
 */
readonly class MetaCampaign
{
    public function __construct(
        /** Salesforce ID */
        public string $id,
        public string $title,
        public string $currencyCode,
        public ?string $status,
        public bool $hidden,
        public bool $ready,
        public ?string $summary,
        public ?string $bannerUri,
        public float $amountRaised,
        public float $matchFundsRemaining,
        public int $donationCount,
        public string $startDate,
        public string $endDate,
        public float $matchFundsTotal,
        public int $campaignCount,
        public bool $usesSharedFunds,
    ) {
    }
}
