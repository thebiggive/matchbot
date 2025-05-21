<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

/**
 * Donation created plus auth JWS model, for responses after a donation is created.
 * @psalm-suppress PossiblyUnusedProperty - used in frontend.
 */
readonly class DonationCreatedResponse
{
    /**
     * @param array<string,mixed> $donation
     */
    public function __construct(
        public array $donation,
        public string $jwt,
        /**
         * @see https://docs.stripe.com/api/customer_sessions/object#customer_session_object-client_secret
         */
        public string $stripeSessionSecret,
    ) {
    }
}
