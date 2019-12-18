<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Full Donation model for both request (webhooks) and response (create and get endpoints) use.
 */
class Donation
{
    public string $transactionId;
    public string $status;
    public string $charityId;
    public float $donationAmount;
    public bool $giftAid;
    public bool $donationMatched;
    public string $firstName;
    public string $lastName;
    public string $emailAddress;
    public string $billingPostalAddress;
    public string $countryCode;
    public bool $optInTbgEmail;
    public string $projectId;
    public float $tipAmount;
}
