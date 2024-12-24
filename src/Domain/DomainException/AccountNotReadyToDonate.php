<?php

declare(strict_types=1);

namespace MatchBot\Domain\DomainException;

use MatchBot\Application\LazyAssertionException;

class AccountNotReadyToDonate extends LazyAssertionException
{
}
