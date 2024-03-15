<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Donation created plus auth JWS model, for responses after a donation is created.
 */
class DonationCreatedResponse
{
    public array $donation;
    public string $jwt;
}
