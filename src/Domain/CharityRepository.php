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
     * @param Salesforce18Id<Charity> $sfId
     * @throws DomainRecordNotFoundException
     */
    public function findOneBySalesforceIdOrThrow(Salesforce18Id $sfId): Charity
    {
        return $this->findOneBySalesforceId($sfId) ??
            throw new DomainRecordNotFoundException('Charity not found');
    }

    /**
     * @param Salesforce18Id<Charity> $sfId
     */
    public function findOneBySalesforceId(Salesforce18Id $sfId): ?Charity
    {
        return $this->findOneBy(['salesforceId' => $sfId->value]);
    }
}
