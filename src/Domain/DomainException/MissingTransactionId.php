<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown when a donation doesn't have a transaction ID, (i.e. stripe payment intent ID). This can happen
 * if there was an error creating the stripe payment intent, e.g. because the account didn't have the required
 * capabilities.
 */
class MissingTransactionId extends \LogicException
{
}
