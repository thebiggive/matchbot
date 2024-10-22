<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown to stop a donation being set up without matching when a full match was expected.
 */
class NotFullyMatched extends DomainException
{
}
