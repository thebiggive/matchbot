<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;

/**
 * Not a SalesforceReadyProxyRepository for now, because we only need to pull charity data
 * as part of Campaign updates, and don't get it from a dedicated Charity/Account API.
 *
 * @see CampaignRepository
 */
class CharityRepository extends EntityRepository
{
}
