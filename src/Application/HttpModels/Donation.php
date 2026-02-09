<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Application\Assertion;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;

/**
 * Donation Data as sent from Frontend for donation updates. Currently, this class is used only ever deserialized,
 * never serialised. Used in Actions\Donations\Update
 *
 */
readonly class Donation
{
    public ?DonorName $donorName;
    public ?EmailAddress $emailAddress;

    /**
     * @psalm-suppress PossiblyUnusedMethod - this constructor is called by the Symfony Serializer
     *
     * some params were once included here included to document that FE sends them, even though
     * we don't do anything with them currently in matchbot. Check git history for details.
     */
    public function __construct(
        public float $donationAmount,
        public string $pspMethodType,
        public ?string $status = null,
        /** @var bool|null Used only to tell credit donations to complete; not persisted. */
        public ?bool $autoConfirmFromCashBalance = null,
        public ?string $currencyCode = null,
        public ?bool $giftAid = null,
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
        public ?string $tipAmount = null,
        public ?bool $tipGiftAid = null,
        public bool $isOrganisationDonor = false
    ) {
        $this->emailAddress = (! is_null($emailAddress) && ! ($emailAddress === ''))
            ? EmailAddress::of($emailAddress)
            : null;

        if ($this->isOrganisationDonor) {
            Assertion::notNull($lastName, 'Last name is required for organisation donors');
            $donorName = DonorName::of('', $lastName);
        } else {
            // we treat N/A as empty since we sometimes replace empty values with N/A to work around salesforce validation,
            // and at least in tests there's a possibility of that getting fed back in to matchbot through an update.
            $donorName = DonorName::maybeFromFirstAndLast($firstName, $lastName);
        }

        $this->donorName = $donorName;

        Assertion::nullOrBetweenLength($this->tipAmount, 1, 9);
        Assertion::nullOrRegex(
            $this->tipAmount,
            '/^[0-9]+(\.\d\d?)?$/',
            "Tip amount should be number with up to two decimals"
        );
    }
}
