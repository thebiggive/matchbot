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
 * @psalm-suppress PossiblyUnusedProperty - instances will be seralised ans used in Front End
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
        /**
         * Salesforce ID of the campaign
         */
        public string $id,

        /**
         * Total amount raised for the campaign in major units (e.g., pounds)
         */
        public float $amountRaised,

        /**
         * Additional images for the campaign with their display order
         * @var list<array{uri: string, order: int}>
         */
        public array $additionalImageUris,

        /**
         * List of campaign aims
         * @var list<string>
         */
        public array $aims,

        /**
         * Alternative use of funds if the campaign doesn't reach its target
         */
        public ?string $alternativeFundUse,

        /**
         * URI of the campaign's banner image
         */
        public ?string $bannerUri,

        /**
         * List of campaign beneficiaries
         * @var list<string>
         */
        public array $beneficiaries,

        /**
         * Breakdown of how funds will be used
         * @var list<array{amount: float, description: string}>
         */
        public array $budgetDetails,

        /** 
         * Approved participating campaign count, for Meta-campaigns
         * @todo mat-405 move to separate meta-campaign model
         */
        public ?int $campaignCount,

        /**
         * List of campaign categories
         * @var list<string>
         */
        public array $categories,

        /**
         * Name of the champion supporting the campaign
         */
        public ?string $championName,

        /**
         * Opt-in statement for the champion
         */
        public ?string $championOptInStatement,

        /** 
         * Champion_Funding__c's slug (if set), or ID, or null if no champion fund 
         */
        public ?string $championRef,

        /**
         * Charity associated with the campaign
         */
        public Charity $charity,

        /**
         * List of countries where the campaign operates
         * @var list<string>
         */
        public array $countries,

        /**
         * ISO 4217 code for the currency in which donations can be accepted and matching is organized
         */
        public string $currencyCode,

        /**
         * Number of donations made to the campaign
         */
        public ?int $donationCount,

        /**
         * The last moment when donors should be able to make an ad-hoc donation or create a new regular giving mandate
         * @var string ISO 8601 formatted date string
         */
        public /* \DateTimeImmutable */ string $endDate,

        /**
         * If true, FE will show a message that donations are currently unavailable for this campaign
         * and searches will exclude it. Very rarely set, available in case a campaign needs to be cancelled or paused quickly.
         */
        public bool $hidden,

        /**
         * Information about how the campaign's impact will be reported
         */
        public ?string $impactReporting,

        /**
         * Summary of the campaign's expected impact
         */
        public ?string $impactSummary,

        /**
         * Whether the campaign has any match funds
         */
        public bool $isMatched,

        /**
         * URI of the campaign's logo
         */
        public ?string $logoUri,

        /**
         * Amount of match funds remaining for the campaign in major units (e.g., pounds)
         */
        public ?float $matchFundsRemaining,

        /**
         * Total amount of match funds allocated to the campaign in major units (e.g., pounds)
         */
        public ?float $matchFundsTotal,

        /**
         * Total amount raised for the parent meta-campaign in major units (e.g., pounds)
         * Only provided if $parentUsesSharedFunds, otherwise null.
         */
        public ?float $parentAmountRaised,

        /**
         * Number of donations made to the parent meta-campaign
         * Only provided if $parentUsesSharedFunds, otherwise null.
         */
        public ?int $parentDonationCount,

        /**
         * Amount of match funds remaining for the parent meta-campaign in major units (e.g., pounds)
         * Only provided if $parentUsesSharedFunds, otherwise null.
         */
        public ?float $parentMatchFundsRemaining,

        /**
         * Parent meta-campaign slug (if set), or ID, or null if no parent
         */
        public ?string $parentRef,

        /**
         * Target amount for the parent meta-campaign in major units (e.g., pounds)
         */
        public ?float $parentTarget,

        /**
         * Whether the parent meta-campaign uses shared funds across all its child campaigns
         */
        public ?bool $parentUsesSharedFunds,

        /**
         * Description of the problem the campaign aims to address
         */
        public ?string $problem,

        /**
         * Testimonial quotes about the campaign
         * @var list<array{person: string, quote: string}>
         */
        public array $quotes,

        /**
         * Dictates whether campaign is/will be ready to accept donations
         * If not ready, the donation journey should be locked in the front-end
         */
        public ?bool $ready,

        /**
         * Description of the solution the campaign proposes
         */
        public ?string $solution,

        /**
         * The first moment when donors should be able to make a donation or a regular giving mandate
         * @var string ISO 8601 formatted date string
         */
        public /* \DateTimeImmutable */  string $startDate,

        public ?string $status,

        /**
         * Whether the campaign accepts regular giving (recurring donations)
         * If false, the campaign only accepts one-off donations
         */
        public bool $isRegularGiving,

        /**
         * Date at which we want to stop collecting payments for this regular giving campaign
         * null on regular giving campaigns means the mandate has no definite end date,
         * on all other non-regular-giving campaigns default to null
         * @var string|null ISO 8601 formatted date string or null
         */
        public /* \DateTimeImmutable */ ?string $regularGivingCollectionEnd,

        /**
         * Brief summary of the campaign
         */
        public ?string $summary,

        /**
         * Information about what happens to surplus donations
         * Set on the meta-campaign level and should house info about awards etc.
         */
        public ?string $surplusDonationInfo,

        /**
         * Target amount for the campaign in major units (e.g., pounds)
         */
        public ?float $target,

        /**
         * Custom message from the charity to donors thanking them for donating
         * Used for regular giving confirmation emails and ad-hoc giving thanks pages and emails
         */
        public string $thankYouMessage,

        /**
         * Title/name of the campaign
         */
        public string $title,

        /**
         * Campaign updates with modification dates
         * @var list<array{content: string, modifiedDate: string}>
         */
        public array $updates,

        /**
         * Whether the campaign uses shared funds with other campaigns
         */
        public bool $usesSharedFunds,

        /**
         * Video information if available
         * @var ?array{provider: string, key: string}
         */
        public ?array $video,
    ) {
    }
}
