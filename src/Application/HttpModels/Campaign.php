<?php

namespace MatchBot\Application\HttpModels;

/**
 * Representation of a charity campaign to serialise and send to FE. Should be assignable to TS campaign model
 * (which we may want to introduce automatic checks for in future) and match the campaign as served from SF.
 *
 * Most fields are marked nullable reflecting the implicit nullability of all fields in Apex objects. Will aim to
 * introduce non-nullable fields where it makes sense as later part of the port to PHP.
 *
 * Not intended to represent a metacampaign, although it may look like it can represent a metacampaign initially
 * due to origin as a port from apex code that represents both metacampaigns and charity campaigns.
 *
 * DateTimeImmutable objects for now are replaced with string (see comments below). May switch back to proper
 * DateTimeImmutable when we have a tool that can render them as ISO.
 *
 * @psalm-suppress PossiblyUnusedProperty - instances will be seralised and used in Front End
 */
readonly class Campaign
{
    /**
     * @param 'Active'|'Expired'|'Preview'|null $status
     * @param list<array{uri: string, order: int}> $additionalImageUris
     * @param list<string> $aims
     * @param list<string> $beneficiaries
     * @param list<array{amount: float, description: string}> $budgetDetails
     * @param list<string> $categories
     * @param list<string> $countries
     * @param list<array{person: string, quote: string}> $quotes
     * @param list<array{content: string, modifiedDate: string}> $updates
     * @param ?array{provider: string, key: string} $video
     * */
    public function __construct(
        public string $id,
        public float $amountRaised,
        public array $additionalImageUris,
        public array $aims,
        public ?string $alternativeFundUse,
        public ?string $bannerUri,
        public array $beneficiaries,
        public array $budgetDetails,
        /** Approved participating campaign count, for Meta-campaigns  (@todo mat-405 move to separate meta-campaign model)*/
        public ?int $campaignCount,
        public array $categories,
        public ?string $championName,
        public ?string $championOptInStatement,
        /** Champion_Funding__c's slug (if set), or ID, or null if no champion fund */
        public ?string $championRef,
        public Charity $charity,
        public array $countries,
        public string $currencyCode,
        public ?int $donationCount,
        public /* \DateTimeImmutable */ string $endDate,
        public bool $hidden,
        public ?string $impactReporting,
        public ?string $impactSummary,
        public bool $isMatched,
        public ?string $logoUri,
        public ?float $matchFundsRemaining,
        public ?float $matchFundsTotal,
        /**
         * Only provided if $parentUsesSharedFunds, otherwise null.
         */
        public ?float $parentAmountRaised,
        /**
         * Only provided if $parentUsesSharedFunds, otherwise null.
         */
        public ?int $parentDonationCount,
        /**
         * Only provided if $parentUsesSharedFunds, otherwise null.
         */
        public ?float $parentMatchFundsRemaining,
        public ?string $parentRef, // Parent meta campaign slug (if set), or ID, or null if $no paren,
        public ?float $parentTarget,
        public ?bool $parentUsesSharedFunds,
        public ?string $problem,
        public array $quotes,
        public ?bool $ready, // Dictates whether or not campaign is ready. If not, lock donation journey in front-$end,
        public ?string $solution,
        public /* \DateTimeImmutable */  string $startDate,
        public ?string $status,
        public bool $isRegularGiving,
        /**
         * null on regular giving campaigns means the mandate has no definite end date,
         * on all other non-regular-giving campaigns default to null
         */
        public /* \DateTimeImmutable */ ?string $regularGivingCollectionEnd,
        public ?string $summary,
        public ?string $surplusDonationInfo, // Set on the meta campaign level and should house info about awards $etc,
        public ?float $target,
        public string $thankYouMessage,
        public string $title,
        public array $updates,
        public bool $usesSharedFunds,
        public ?array $video,
    ) {
    }
}
