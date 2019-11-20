<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Full Donation model for both request (webhooks) and response (create and get endpoints) use.
 */
class Donation
{
    /** @var string */
    public $transactionId;

    /** @var string */
    public $status;

    /** @var string */
    public $charityId;

    /** @var float */
    public $donationAmount;

    /** @var bool */
    public $giftAid;

    /** @var bool */
    public $donationMatched;

    /** @var string */
    public $firstName;

    /** @var string */
    public $lastName;

    /** @var string */
    public $emailAddress;

    /** @var string */
    public $billingPostalAddress;

    /** @var string */
    public $countryCode;

    /** @var bool */
    public $optInTbgEmail;

    /** @var string */
    public $projectId;

    /** @var float */
    public $tipAmount;
}
