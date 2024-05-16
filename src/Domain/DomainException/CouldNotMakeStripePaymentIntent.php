<?php

declare(strict_types=1);

namespace MatchBot\Domain\DomainException;

class CouldNotMakeStripePaymentIntent extends DomainException
{
    public function __construct(
        public readonly bool $accountLacksCapabilities,
        string $message = "",
    ) {
        parent::__construct($message);
    }
}
