<?php

namespace MatchBot\Application\HttpModels;

use OpenApi\Attributes as OA;

/**
 * Recently ported over from equivalent class in Apex. Will have some design choices etc
 * that reflect that origin until it has time to settle into PHP - e.g many fields are marked nullable
 * because in Apex all fields are implictly nullable.
 *
 * @psalm-suppress PossiblyUnusedProperty - instances will be seralised ans used in Front End
 */
#[OA\Schema(description: "Charity information for use in the Front End")]
readonly class Charity
{
    public function __construct(
        #[OA\Property(
            property: "id",
            description: "Unique identifier for the charity",
            example: "a1b2c3d4-e5f6-g7h8-i9j0-k1l2m3n4o5p6"
        )]
        public string $id,
        #[OA\Property(
            property: "name",
            description: "Name of the charity",
            example: "Example Charity"
        )]
        public ?string $name,
        #[OA\Property(
            property: "optInStatement",
            description: "Opt-in statement for the charity",
            example: "I would like to hear about future campaigns from this charity"
        )]
        public ?string $optInStatement,
        #[OA\Property(
            property: "facebook",
            description: "Facebook page URL for the charity",
            example: "https://facebook.com/examplecharity"
        )]
        public ?string $facebook,
        #[OA\Property(
            property: "giftAidOnboardingStatus",
            description: "Status of the charity's Gift Aid onboarding",
            example: "Complete"
        )]
        public ?string $giftAidOnboardingStatus,
        #[OA\Property(
            property: "hmrcReferenceNumber",
            description: "HMRC reference number for the charity",
            example: "AB12345"
        )]
        public ?string $hmrcReferenceNumber,
        #[OA\Property(
            property: "instagram",
            description: "Instagram handle for the charity",
            example: "examplecharity"
        )]
        public ?string $instagram,
        #[OA\Property(
            property: "linkedin",
            description: "LinkedIn URL for the charity",
            example: "https://linkedin.com/company/examplecharity"
        )]
        public ?string $linkedin,
        #[OA\Property(
            property: "twitter",
            description: "Twitter/X handle for the charity",
            example: "examplecharity"
        )]
        public ?string $twitter,
        #[OA\Property(
            property: "website",
            description: "Website URL for the charity",
            example: "https://examplecharity.org"
        )]
        public ?string $website,
        #[OA\Property(
            property: "phoneNumber",
            description: "Contact phone number for the charity",
            example: "+44123456789"
        )]
        public ?string $phoneNumber,
        #[OA\Property(
            property: "emailAddress",
            description: "Contact email address for the charity",
            example: "contact@examplecharity.org"
        )]
        public ?string $emailAddress,
        #[OA\Property(
            property: "regulatorNumber",
            description: "Charity regulator reference number",
            example: "123456"
        )]
        public ?string $regulatorNumber,
        #[OA\Property(
            property: "regulatorRegion",
            description: "Region of the charity regulator",
            example: "England and Wales"
        )]
        public ?string $regulatorRegion,
        #[OA\Property(
            property: "logoUri",
            description: "URI for the charity's logo",
            example: "https://example.com/logo.png"
        )]
        public ?string $logoUri,
        #[OA\Property(
            property: "stripeAccountId",
            description: "Stripe account ID for the charity",
            example: "acct_123456789"
        )]
        public ?string $stripeAccountId
    ) {
    }
}
