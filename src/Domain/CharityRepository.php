<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;

/**
 * Not a SalesforceReadyProxyRepository for now, because we currently only pull basic charity data (SF ID and name)
 * as part of Campaign updates, and don't get this from a dedicated Charity/Account API.
 *
 * @see CampaignRepository
 */
class CharityRepository extends EntityRepository
{
}
