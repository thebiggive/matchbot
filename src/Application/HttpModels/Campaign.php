<?php

namespace MatchBot\Application\HttpModels;

use OpenApi\Attributes as OA;

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
#[OA\Schema(description: "Charity campaign information for use in the Front End")]
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
        #[OA\Property(
            property: "id",
            description: "Unique identifier for the campaign",
            example: "a1b2c3d4-e5f6-g7h8-i9j0-k1l2m3n4o5p6"
        )]
        public string $id,
        #[OA\Property(
            property: "amountRaised",
            description: "Total raised by the campaign so far. Includes match commitments secured and no fees are deducted.",
            example: 25000.50
        )]
        public float $amountRaised,
        #[OA\Property(
            property: "additionalImageUris",
            description: "List of additional images for the campaign",
            type: "array",
            items: new OA\Items(
                type: "object",
                properties: [
                    new OA\Property(property: "uri", type: "string", example: "https://example.com/image1.jpg"),
                    new OA\Property(property: "order", type: "integer", example: 1)
                ]
            )
        )]
        public array $additionalImageUris,
        #[OA\Property(
            property: "aims",
            description: "List of campaign aims",
            type: "array",
            items: new OA\Items(type: "string", example: "Provide clean water to 1000 families")
        )]
        public array $aims,
        #[OA\Property(
            property: "alternativeFundUse",
            description: "Description of alternative use of funds if target not reached",
            example: "Funds will be used for our general charitable purposes"
        )]
        public ?string $alternativeFundUse,
        #[OA\Property(
            property: "bannerUri",
            description: "URI for the campaign's banner image",
            example: "https://example.com/banner.jpg"
        )]
        public ?string $bannerUri,
        #[OA\Property(
            property: "beneficiaries",
            description: "List of campaign beneficiaries",
            type: "array",
            items: new OA\Items(type: "string", example: "Children in rural communities")
        )]
        public array $beneficiaries,
        #[OA\Property(
            property: "budgetDetails",
            description: "Budget breakdown for the campaign",
            type: "array",
            items: new OA\Items(
                type: "object",
                properties: [
                    new OA\Property(property: "amount", type: "number", format: "float", example: 5000.00),
                    new OA\Property(property: "description", type: "string", example: "Equipment costs")
                ]
            )
        )]
        public array $budgetDetails,
        #[OA\Property(
            property: "campaignCount",
            description: "Approved participating campaign count, for Meta-campaigns",
            example: 10
        )]
        public ?int $campaignCount,
        #[OA\Property(
            property: "categories",
            description: "List of campaign categories",
            type: "array",
            items: new OA\Items(type: "string", example: "Health")
        )]
        public array $categories,
        #[OA\Property(
            property: "championName",
            description: "Name of the campaign champion",
            example: "John Smith Foundation"
        )]
        public ?string $championName,
        #[OA\Property(
            property: "championOptInStatement",
            description: "Opt-in statement for the campaign champion",
            example: "I would like to hear about future campaigns from this champion"
        )]
        public ?string $championOptInStatement,
        #[OA\Property(
            property: "championRef",
            description: "Champion_Funding__c's slug (if set), or ID, or null if no champion fund",
            example: "john-smith-foundation"
        )]
        public ?string $championRef,
        #[OA\Property(
            property: "charity",
            description: "Charity information for this campaign",
            ref: "#/components/schemas/Charity"
        )]
        public Charity $charity,
        #[OA\Property(
            property: "countries",
            description: "List of countries where the campaign operates",
            type: "array",
            items: new OA\Items(type: "string", example: "United Kingdom")
        )]
        public array $countries,
        #[OA\Property(
            property: "currencyCode",
            description: "Currency code for the campaign",
            example: "GBP"
        )]
        public string $currencyCode,
        #[OA\Property(
            property: "donationCount",
            description: "Number of donations made to the campaign",
            example: 250
        )]
        public ?int $donationCount,
        #[OA\Property(
            property: "endDate",
            description: "End date of the campaign in ISO 8601 format",
            example: "2025-12-07T12:00:00Z"
        )]
        public /* \DateTimeImmutable */ string $endDate,
        #[OA\Property(
            property: "hidden",
            description: "Whether the campaign is hidden from public view",
            example: false
        )]
        public bool $hidden,
        #[OA\Property(
            property: "impactReporting",
            description: "Information about how impact will be reported",
            example: "We will provide quarterly updates on our progress"
        )]
        public ?string $impactReporting,
        #[OA\Property(
            property: "impactSummary",
            description: "Summary of the campaign's impact",
            example: "This project will help 500 families access clean water"
        )]
        public ?string $impactSummary,
        #[OA\Property(
            property: "isMatched",
            description: "Whether the campaign has match funding",
            example: true
        )]
        public bool $isMatched,
        #[OA\Property(
            property: "logoUri",
            description: "URI for the campaign's logo",
            example: "https://example.com/logo.png"
        )]
        public ?string $logoUri,
        #[OA\Property(
            property: "matchFundsRemaining",
            description: "Amount of match funds remaining for the campaign",
            example: 12500.25
        )]
        public ?float $matchFundsRemaining,
        #[OA\Property(
            property: "matchFundsTotal",
            description: "Total match funds allocated to the campaign",
            example: 25000.00
        )]
        public ?float $matchFundsTotal,
        #[OA\Property(
            property: "parentAmountRaised",
            description: "Amount raised by the parent campaign (only provided if parentUsesSharedFunds)",
            example: 100000.00
        )]
        public ?float $parentAmountRaised,
        #[OA\Property(
            property: "parentDonationCount",
            description: "Number of donations to the parent campaign (only provided if parentUsesSharedFunds)",
            example: 1000
        )]
        public ?int $parentDonationCount,
        #[OA\Property(
            property: "parentMatchFundsRemaining",
            description: "Match funds remaining in the parent campaign (only provided if parentUsesSharedFunds)",
            example: 50000.00
        )]
        public ?float $parentMatchFundsRemaining,
        #[OA\Property(
            property: "parentRef",
            description: "Parent meta campaign slug (if set), or ID, or null if no parent",
            example: "christmas-challenge-2025"
        )]
        public ?string $parentRef,
        #[OA\Property(
            property: "parentTarget",
            description: "Fundraising target of the parent campaign",
            example: 200000.00
        )]
        public ?float $parentTarget,
        #[OA\Property(
            property: "parentUsesSharedFunds",
            description: "Whether the parent campaign uses shared funds across campaigns",
            example: true
        )]
        public ?bool $parentUsesSharedFunds,
        #[OA\Property(
            property: "problem",
            description: "Description of the problem the campaign addresses",
            example: "Many communities lack access to clean water"
        )]
        public ?string $problem,
        #[OA\Property(
            property: "quotes",
            description: "Quotes related to the campaign",
            type: "array",
            items: new OA\Items(
                type: "object",
                properties: [
                    new OA\Property(property: "person", type: "string", example: "Jane Doe"),
                    new OA\Property(property: "quote", type: "string", example: "This project changed my life")
                ]
            )
        )]
        public array $quotes,
        #[OA\Property(
            property: "ready",
            description: "Whether the campaign is ready for donations (if not, donation journey is locked)",
            example: true
        )]
        public ?bool $ready,
        #[OA\Property(
            property: "solution",
            description: "Description of the solution the campaign provides",
            example: "We will install water filtration systems in 20 villages"
        )]
        public ?string $solution,
        #[OA\Property(
            property: "startDate",
            description: "Start date of the campaign in ISO 8601 format",
            example: "2025-11-30T12:00:00Z"
        )]
        public /* \DateTimeImmutable */  string $startDate,
        #[OA\Property(
            property: "status",
            description: "Status of the campaign",
            example: "Active",
            enum: ["Active", "Expired", "Preview"]
        )]
        public ?string $status,
        #[OA\Property(
            property: "isRegularGiving",
            description: "Whether the campaign accepts regular giving donations",
            example: false
        )]
        public bool $isRegularGiving,
        #[OA\Property(
            property: "regularGivingCollectionEnd",
            description: "End date for regular giving collections (null means no definite end date)",
            example: "2026-11-30T12:00:00Z"
        )]
        public /* \DateTimeImmutable */ ?string $regularGivingCollectionEnd,
        #[OA\Property(
            property: "summary",
            description: "Summary description of the campaign",
            example: "Providing clean water to rural communities"
        )]
        public ?string $summary,
        #[OA\Property(
            property: "surplusDonationInfo",
            description: "Information about how surplus donations will be used",
            example: "Any surplus funds will be used for our other water projects"
        )]
        public ?string $surplusDonationInfo,
        #[OA\Property(
            property: "target",
            description: "Fundraising target for the campaign",
            example: 50000.00
        )]
        public ?float $target,
        #[OA\Property(
            property: "thankYouMessage",
            description: "Thank you message shown to donors after donation",
            example: "Thank you for your generous support!"
        )]
        public string $thankYouMessage,
        #[OA\Property(
            property: "title",
            description: "Title of the campaign",
            example: "Clean Water for All"
        )]
        public string $title,
        #[OA\Property(
            property: "updates",
            description: "Updates posted about the campaign",
            type: "array",
            items: new OA\Items(
                type: "object",
                properties: [
                    new OA\Property(property: "content", type: "string", example: "We've reached 50% of our target!"),
                    new OA\Property(property: "modifiedDate", type: "string", example: "2025-12-02T15:30:00Z")
                ]
            )
        )]
        public array $updates,
        #[OA\Property(
            property: "usesSharedFunds",
            description: "Whether the campaign uses shared funds with other campaigns",
            example: false
        )]
        public bool $usesSharedFunds,
        #[OA\Property(
            property: "video",
            description: "Video information for the campaign",
            type: "object",
            properties: [
                new OA\Property(property: "provider", type: "string", example: "youtube"),
                new OA\Property(property: "key", type: "string", example: "dQw4w9WgXcQ")
            ]
        )]
        public ?array $video,
    ) {
    }
}
