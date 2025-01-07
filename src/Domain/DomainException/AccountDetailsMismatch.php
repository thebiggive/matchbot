<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown to stop creation of a regular giving mandate with donor details that differ from those on donor account.
 *
 * If they have one thing on the account and another on the mandate creation request we can't tell which details
 * they want us to keep.
 */
class AccountDetailsMismatch extends DomainException
{
}
