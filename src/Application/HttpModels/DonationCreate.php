<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Application\AssertionFailedException;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;

/**
 * @psalm-immutable
 * Request-only payload for setting up new donations.
 */
class DonationCreate
{
    public readonly Salesforce18Id $projectId;

    /**
     * @param string $donationAmount In full currency unit, e.g. whole pounds GBP, whole dollars USD
     * @psalm-param numeric-string $donationAmount
     * @throws AssertionFailedException
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $donationAmount,
        string $projectId,
        public readonly string $psp,
        public readonly PaymentMethodType $pspMethodType = PaymentMethodType::Card,
        public readonly ?string $countryCode = null,
        public readonly ?string $feeCoverAmount = '0.00',
        public readonly ?bool $optInCharityEmail = null,
        public readonly ?bool $optInChampionEmail = null,
        public readonly ?bool $optInTbgEmail = null,
        public readonly ?string $pspCustomerId = null,
        public readonly ?string $tipAmount = '0.00',
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $emailAddress = null
    ) {
        $this->projectId = Salesforce18Id::of($projectId);
    }
}
