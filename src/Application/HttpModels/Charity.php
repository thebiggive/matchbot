<?php

namespace MatchBot\Application\HttpModels;

/**
 * Recently ported over from equivalent class in Apex. Will have some design choices etc
 * that reflect that origin until it has time to settle into PHP - e.g many fields are marked nullable
 * because in Apex all fields are implictly nullable.
 *
 * @psalm-suppress PossiblyUnusedProperty - instances will be seralised ans used in Front End
 */
readonly class Charity
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $optInStatement,
        public ?string $facebook,
        public ?string $giftAidOnboardingStatus,
        public ?string $hmrcReferenceNumber,
        public ?string $instagram,
        public ?string $linkedin,
        public ?string $twitter,
        public ?string $website,
        public ?string $phoneNumber,
        public ?string $emailAddress,
        public ?string $regulatorNumber,
        public ?string $regulatorRegion,
        public ?string $logoUri,
        public ?string $stripeAccountId
    ) {
    }
}
