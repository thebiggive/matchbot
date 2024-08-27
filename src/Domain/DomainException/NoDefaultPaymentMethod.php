<?php

namespace MatchBot\Domain\DomainException;

/**
 * We cannot collect a regular giving payment if our donor does not have a default payment method set.
 */
class NoDefaultPaymentMethod extends DomainException
{
}
