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
    /** @var bool|null Used only to tell credit donations to complete; not persisted. */
    public ?bool $autoConfirmFromCashBalance = false;
    public ?string $currencyCode = null;
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
    public ?bool $tipGiftAid = null;
}
