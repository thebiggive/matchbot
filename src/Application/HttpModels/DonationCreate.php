<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Domain\PaymentMethodType;

/**
 * Request-only payload for setting up new donations.
 * @psalm-suppress MissingConstructor Seems we can't use an enum w/o default without hitting this.
 */
class DonationCreate
{
    public ?string $countryCode = null;
    public ?string $currencyCode = null; // Create will set this to GBP if null on init, for now.
    /** @var string In full currency unit, e.g. whole pounds GBP, whole dollars USD */
    public string $donationAmount;
    public ?string $feeCoverAmount = '0.00';
    public ?bool $giftAid = null;
    public ?bool $optInCharityEmail = null;
    public ?bool $optInChampionEmail = null;
    public ?bool $optInTbgEmail = null;
    public PaymentMethodType $paymentMethodType;
    public string $projectId;
    public string $psp;
    public ?string $pspCustomerId = null;
    public ?string $tipAmount = '0.00';
}
