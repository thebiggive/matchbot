<?php

namespace MatchBot\Domain\DomainException;

/**
 * Thrown if a fund change other than Pledge to TopupPledge happens in Salesforce. (Eventually, we also
 * want to prevent this at source, at least for already started campaigns.)
 */
class DisallowedFundTypeChange extends DomainException
{
}
