<?php

namespace MatchBot\Application\HttpModels;

use MatchBot\Domain\BannerLayout;
use OpenApi\Annotations as OA;

/**
 * Representation of a Meta Campaign to serialise and send to FE. Should be assignable to TS MetaCampaign model
 * (which we may want to introduce automatic checks for in future) and match the MetaCampaign as served from SF.
 *
 * @psalm-suppress PossiblyUnusedProperty - instances will be seralised and used in Front End
 *
 * @OA\Schema(
 *   description="Meta Campaign information for use in the Front End",
 * )
 */
readonly class MetaCampaign
{
    public function __construct(
        /**
         * @OA\Property(
         *   property="id",
         *   description="Salesforce ID of the meta campaign",
         *   example="a1b2c3d4e5f6"
         * )
         */
        public string $id,
        
        /**
         * @OA\Property(
         *   property="title",
         *   description="Title of the meta campaign",
         *   example="Big Give Christmas Challenge 2025"
         * )
         */
        public string $title,
        
        /**
         * @OA\Property(
         *   property="currencyCode",
         *   description="Currency code for the meta campaign",
         *   example="GBP"
         * )
         */
        public string $currencyCode,
        
        /**
         * @OA\Property(
         *   property="status",
         *   description="Status of the meta campaign",
         *   example="Active",
         *   enum={"Active", "Expired", "Preview"}
         * )
         */
        public ?string $status,
        
        /**
         * @OA\Property(
         *   property="hidden",
         *   description="Whether the meta campaign is hidden from public view",
         *   example=false
         * )
         */
        public bool $hidden,
        
        /**
         * @OA\Property(
         *   property="ready",
         *   description="Whether the meta campaign is ready for donations",
         *   example=true
         * )
         */
        public bool $ready,
        
        /**
         * @OA\Property(
         *   property="summary",
         *   description="Summary description of the meta campaign",
         *   example="Our annual match funding campaign"
         * )
         */
        public ?string $summary,
        
        /**
         * @OA\Property(
         *   property="bannerUri",
         *   description="URI for the meta campaign's banner image",
         *   example="https://example.com/banner.jpg"
         * )
         */
        public ?string $bannerUri,
        
        /**
         * @OA\Property(
         *   property="amountRaised",
         *   description="Total amount raised in the meta campaign",
         *   example=1000000.50
         * )
         */
        public float $amountRaised,
        
        /**
         * @OA\Property(
         *   property="matchFundsRemaining",
         *   description="Amount of match funds remaining in the meta campaign",
         *   example=500000.25
         * )
         */
        public float $matchFundsRemaining,
        
        /**
         * @OA\Property(
         *   property="donationCount",
         *   description="Number of donations made to the meta campaign",
         *   example=5000
         * )
         */
        public int $donationCount,
        
        /**
         * @OA\Property(
         *   property="startDate",
         *   description="Start date of the meta campaign in ISO 8601 format",
         *   example="2025-11-30T12:00:00Z"
         * )
         */
        public string $startDate,
        
        /**
         * @OA\Property(
         *   property="endDate",
         *   description="End date of the meta campaign in ISO 8601 format",
         *   example="2025-12-07T12:00:00Z"
         * )
         */
        public string $endDate,
        
        /**
         * @OA\Property(
         *   property="matchFundsTotal",
         *   description="Total match funds allocated to the meta campaign",
         *   example=1000000.00
         * )
         */
        public float $matchFundsTotal,
        
        /**
         * @OA\Property(
         *   property="campaignCount",
         *   description="Number of approved participating campaigns",
         *   example=500
         * )
         */
        public int $campaignCount,
        
        /**
         * @OA\Property(
         *   property="usesSharedFunds",
         *   description="Whether the meta campaign uses shared funds across campaigns",
         *   example=true
         * )
         */
        public bool $usesSharedFunds,
        
        /**
         * @OA\Property(
         *   property="useDon1120Banner",
         *   description="Whether the page for this campaign uses the new style of banner display created in ticket DON-1120",
         *   example=false
         * )
         */
        public bool $useDon1120Banner = false,
        
        /**
         * @OA\Property(
         *   property="bannerLayout",
         *   description="Layout configuration for the campaign banner",
         *   ref="#/components/schemas/BannerLayout"
         * )
         */
        public ?BannerLayout $bannerLayout = null,
    ) {
    }
}
