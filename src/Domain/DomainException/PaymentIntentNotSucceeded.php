<?php

namespace MatchBot\Domain\DomainException;

use MatchBot\Domain\RegularGivingMandate;
use Stripe\PaymentIntent;

class PaymentIntentNotSucceeded extends \Exception
{
    /** Todo - remove this property and probably whole exception, use return values instead of throwing. */
    public ?RegularGivingMandate $mandate = null;

    public function __construct(
        public readonly PaymentIntent $paymentIntent,
        string $message,
    ) {
        parent::__construct($message, 0, null);
    }
}
