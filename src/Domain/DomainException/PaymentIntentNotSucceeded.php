<?php

namespace MatchBot\Domain\DomainException;

use Stripe\PaymentIntent;

class PaymentIntentNotSucceeded extends \Exception {
    public function __construct(
        public readonly PaymentIntent $paymentIntent,
        string $message,
    ) {
        parent::__construct($message, 0, null);
    }
}