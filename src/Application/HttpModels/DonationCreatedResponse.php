<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Donation created plus auth JWS model, for responses after a donation is created.
 * @psalm-suppress PossiblyUnusedProperty - used in frontend.
 */
class DonationCreatedResponse
{
    public array $donation;
    public string $jwt;
}
