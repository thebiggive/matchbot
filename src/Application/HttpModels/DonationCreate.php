<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Request-only payload for setting up new donations.
 */
class DonationCreate
{
    public string $donationAmount;
    public bool $giftAid;
    public bool $optInCharityEmail;
    public bool $optInTbgEmail;
    public string $projectId;
}
