<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Donation created plus auth JWS model, for responses after a donation is created.
 */
class DonationCreatedResponse
{
    /**
     * @var array $donation Properties are as per `Donation` but internal type is always `array`
     * @see Donation
     */
    public array $donation;
    public string $jwt;
}
