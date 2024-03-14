<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Application\AssertionFailedException;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;

/**
 * @psalm-immutable
 * Request-only payload for setting up new donations.
 */
readonly class DonationCreate
{
    public readonly Salesforce18Id $projectId;
    public readonly ?DonorName $donorName;
    public readonly ?EmailAddress $emailAddress;

    /**
     * @param string $donationAmount In full currency unit, e.g. whole pounds GBP, whole dollars USD
     * @psalm-param numeric-string $donationAmount
     * @throws AssertionFailedException
     */
    public function __construct(
        public string $currencyCode,
        public string $donationAmount,
        string $projectId,
        public string $psp,
        public PaymentMethodType $pspMethodType = PaymentMethodType::Card,
        public ?string $countryCode = null,
        public ?string $feeCoverAmount = '0.00',
        public ?bool $optInCharityEmail = null,
        public ?bool $optInChampionEmail = null,
        public ?bool $optInTbgEmail = null,
        public ?string $pspCustomerId = null,
        public ?string $tipAmount = '0.00',
        ?string $firstName = null,
        public ?string $lastName = null,
        ?string $emailAddress = null
    ) {
        $this->emailAddress = (! is_null($emailAddress) && ! ($emailAddress === '')) ?
            EmailAddress::of($emailAddress) :
            null;

        $this->donorName = DonorName::maybeFromFirstAndLast($firstName, $this->lastName);

        $this->projectId = Salesforce18Id::of($projectId);
    }
}
