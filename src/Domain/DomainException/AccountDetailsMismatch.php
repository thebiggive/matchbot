<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown to stop creation of a regular giving mandate with donor details that differ from those on donor account.
 *
 * If they have one thing on the account and another on the mandate creation request we can't tell which details
 * they want us to keep.
 *
 * This can happen if a donor tries setting up two regular giving mandates in parallel, either for the same
 * or different campaigns, or has the regular giving form open while making changes in their account area.
 */
class AccountDetailsMismatch extends DomainException
{
}
