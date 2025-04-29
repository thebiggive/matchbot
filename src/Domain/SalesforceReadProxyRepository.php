<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Client;

/**
 * @template T of SalesforceReadProxy
 * @template C of Client\Common
 * @template-extends SalesforceProxyRepository<T, C>
 */
abstract class SalesforceReadProxyRepository extends SalesforceProxyRepository
{
    /**
     * Get live data for the object (which might be empty apart from the Salesforce ID) and return a full object.
     * No need to `setSalesforceLastPull()`, or EM `persist()` - just populate the fields specific to the object.
     *
     * @psalm-param T $proxy
     * @throws UniqueConstraintViolationException occasionally if 2 requests try to create the same
     *                                            Salesforce object in parallel.
     */
    abstract protected function doUpdateFromSf(SalesforceReadProxy $proxy, bool $withCache): void;

    /**
     * @psalm-param T $proxy
     * @throws Client\NotFoundException
     */
    public function updateFromSf(
        SalesforceReadProxy $proxy,
        bool $withCache = true,
        bool $autoSave = true,
    ): void {
        // Make sure we update existing object if passed in a partial copy and we already have that Salesforce object
        // persisted, otherwise we'll try to insert a duplicate and get an ORM crash.
        $salesforceId = $proxy->getSalesforceId();
        if ($salesforceId === null) {
            $this->logWarning(
                'Cannot update ' .
                get_class($proxy) . ' without SF ID for internal ID ' .
                ($proxy->getId() ?? -1)
            );
            return;
        }

        if (
            $proxy->hasBeenPersisted() &&
            ($existingProxy = $this->findOneBy(['salesforceId' => $salesforceId]))
        ) {
            $this->logInfo('Updating ' . get_class($proxy) . ' ' . $salesforceId . '...');
            $proxy = $existingProxy;
        } else {
            $this->logInfo('Creating ' . get_class($proxy) . ' ' . $salesforceId);
        }

        $this->doUpdateFromSf($proxy, $withCache);

        $proxy->setSalesforceLastPull(new DateTime('now'));
        $this->getEntityManager()->persist($proxy);
        if ($autoSave) {
            $this->getEntityManager()->flush();
        }

        $this->logInfo('Done persisting ' . get_class($proxy) . ' ' . $salesforceId);
    }
}
