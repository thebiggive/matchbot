<?php

declare(strict_types=1);

namespace MatchBot\Domain\DomainException;

/**
 * General purpose exception for when we've received a user-command that we can't or won't honour. The user
 * should see whatever message is on here.
 */
class BadCommandException extends DomainException
{
}
