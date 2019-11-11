<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Donation created plus auth JWS model, for responses after a donation is created.
 */
class DonationCreatedResponse
{
    /** @var Donation */
    public $donation;

    /** @var string */
    public $jwt;
}
