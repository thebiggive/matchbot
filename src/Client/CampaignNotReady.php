<?php

namespace MatchBot\Client;

/**
 * Thrown when we attempt to pull a campaign from SF but SF tells us it is not 'ready', i.e.
 * CampaignServiceModel.ready is false over there. Has happened at least once but generally shouldn't in prod
 * as we don't want to pull campaigns before they're ready and once ready they should not go back to unready.
 */
class CampaignNotReady extends \Exception
{
}
