<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown to stop a regular giving campaign being used for a one-off donation, or vice versa.
 */
class WrongCampaignType extends DomainException
{
}
