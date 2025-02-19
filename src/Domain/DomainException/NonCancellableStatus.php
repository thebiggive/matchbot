<?php

declare(strict_types=1);

namespace MatchBot\Domain\DomainException;

/**
 * Thrown on attempt to collect a payment in relation to a regular giving agreement when the campaign's last collection
 * date is or will be passed.
 */
class NonCancellableStatus extends DomainException
{
}
