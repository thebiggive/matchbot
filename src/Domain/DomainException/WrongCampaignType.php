<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown to stop a regular giving campaign being used for an ad-hoc donation, or vice versa.
 */
class WrongCampaignType extends DomainException
{
}
