<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Application\Assertion;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;

/**
 * Donation Data as sent from Frontend for donation updates. Currently, this class is used only ever deserialized,
 * never serialised. Used in Actions\Donations\Update
 */
readonly class Donation
{
    public ?DonorName $donorName;
    public ?EmailAddress $emailAddress;

    /**
     * @psalm-suppress PossiblyUnusedMethod - this constructor is called bye the Symfony Serializer
     */
    public function __construct(
        public float $donationAmount,
        public ?string $transactionId = null,
        public ?string $status = null,
        public ?string $charityId = null,
        /** @var bool|null Used only to tell credit donations to complete; not persisted. */
        public ?bool $autoConfirmFromCashBalance = null,
        public ?string $currencyCode = null,
        public ?float $feeCoverAmount = null,
        public ?bool $giftAid = null,
        public ?bool $donationMatched = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $emailAddress = null,
        public ?string $billingPostalAddress = null,
        public ?string $countryCode = null,
        public ?string $homeAddress = null,
        public ?string $homePostcode = null,
        public ?bool $optInTbgEmail = null,
        public ?bool $optInCharityEmail = null,
        public ?bool $optInChampionEmail = null,
        public ?string $projectId = null,
        public ?float $tipAmount = null,
        public ?bool $tipGiftAid = null,
    ) {
        $this->emailAddress = (! is_null($emailAddress) && ! ($emailAddress === ''))
            ? EmailAddress::of($emailAddress)
            : null;

        // we treat N/A as empty since we sometimes replace empty values with N/A to work around salesforce validation,
        // and at least in tests there's a possiblity of that getting fed back in to matchbot through an update.
        $donorName = DonorName::maybeFromFirstAndLast($firstName, $lastName);

        $this->donorName = $donorName;
    }
}
