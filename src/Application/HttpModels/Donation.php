<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Full Donation model for both request (webhooks) and response (create and get endpoints) use.
 */
class Donation
{
    public ?string $transactionId = null;
    public string $status;
    public string $charityId;
    /** @var string|null Used only on creates; not persisted. */
    public ?string $creationRecaptchaCode = null;
    public ?string $currencyCode = 'GBP';
    public float $donationAmount;
    public ?float $feeCoverAmount = null;
    public ?bool $giftAid;
    public bool $donationMatched;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $emailAddress = null;
    public ?string $billingPostalAddress = null;
    public ?string $countryCode = null;
    public ?string $homeAddress = null;
    public ?string $homePostcode = null;
    public ?bool $optInTbgEmail;
    public ?bool $optInCharityEmail = null;
    public ?bool $optInChampionEmail = null;
    public string $projectId;
    public ?float $tipAmount = null;
    public ?float $refundedTipAmount = null;
    public ?bool $tipGiftAid = null;
    public ?string $cardBrand = null;
    public ?string $cardCountry = null;
}
