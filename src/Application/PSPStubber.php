<?php

namespace MatchBot\Application;

use Ramsey\Uuid\Uuid;

/**
 * @deprecated - Will put in a better solution for MAT-327
 * Stripe does not allow high quantity load testing in test mode, so we have to stub it out
 * for the scenarios that we load test, as reccomened at https://stripe.com/docs/rate-limits
 */
class PSPStubber
{
    /**
     * Not where I'd keep a function like this long term, should work for now though.
     */
    public static function byPassStripe(): bool
    {
        return getenv('APP_ENV') !== 'production' && getenv('BYPASS_PSP');
    }
}