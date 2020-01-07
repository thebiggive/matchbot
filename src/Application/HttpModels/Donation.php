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
    public float $donationAmount;
    public bool $giftAid;
    public bool $donationMatched;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $emailAddress = null;
    public ?string $billingPostalAddress = null;
    public ?string $countryCode = null;
    public bool $optInTbgEmail;
    public string $projectId;
    public ?float $tipAmount = null;
}
