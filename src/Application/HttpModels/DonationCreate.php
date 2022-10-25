<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Request-only payload for setting up new donations.
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
    public string $paymentMethodType = 'card'; // 'card' or 'customer_balance'.
    public string $projectId;
    public string $psp;
    public ?string $pspCustomerId = null;
    public ?string $tipAmount = '0.00';
}
