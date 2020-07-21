<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Request-only payload for setting up new donations.
 */
class DonationCreate
{
    /** @var string In full pounds GBP */
    public string $donationAmount;
    public bool $giftAid;
    public bool $optInCharityEmail;
    public bool $optInTbgEmail;
    public string $projectId;
    public string $psp;
}
