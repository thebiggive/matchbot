<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Request-only payload for setting up new donations.
 */
class DonationCreate
{
    /** @var string */
    public $donationAmount;

    /** @var bool */
    public $giftAid;

    /** @var bool */
    public $optInCharityEmail;

    /** @var bool */
    public $optInTbgEmail;

    /** @var string */
    public $projectId;
}
