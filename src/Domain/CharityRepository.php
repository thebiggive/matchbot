<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;

/**
 * Not a SalesforceReadyProxyRepository for now, because we only need to pull charity data
 * as part of Campaign updates, and don't get it from a dedicated Charity/Account API.
 *
 * @extends EntityRepository<Charity>
 * @see CampaignRepository
 */
class CharityRepository extends EntityRepository
{
    /**
     * @throws DomainRecordNotFoundException
     */
    public function findOneBySfIDOrThrow(Salesforce18Id $sfId): Charity
    {
        return $this->findOneBy(['salesforceId' => $sfId->value]) ??
            throw new DomainRecordNotFoundException('Charity not found');
    }
}
