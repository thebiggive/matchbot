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
    public ?bool $giftAid = null;
    public ?bool $optInCharityEmail = null;
    public ?bool $optInTbgEmail = null;
    public string $projectId;
    public string $psp;
}
