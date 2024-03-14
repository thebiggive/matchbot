<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Full Donation model for both request (webhooks) and response (create and get endpoints) use.
 */
readonly class Donation
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - this constructor is called bye the Symfony Serializer
     */
    public function __construct(
        public ?string $transactionId = null,
        public ?string $status = null,
        public ?string $charityId = null,
        /** @var bool|null Used only to tell credit donations to complete; not persisted. */
        public ?bool $autoConfirmFromCashBalance = null,
        public ?string $currencyCode = null,
        public float $donationAmount,
        public ?float $feeCoverAmount = null,
        public ?bool $giftAid,
        public ?bool $donationMatched = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $emailAddress = null,
        public ?string $billingPostalAddress = null,
        public ?string $countryCode = null,
        public ?string $homeAddress = null,
        public ?string $homePostcode = null,
        public ?bool $optInTbgEmail,
        public ?bool $optInCharityEmail = null,
        public ?bool $optInChampionEmail = null,
        public ?string $projectId = null,
        public ?float $tipAmount = null,
        public ?bool $tipGiftAid = null,
    ) {
    }
}
