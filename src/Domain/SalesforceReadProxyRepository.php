<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;

abstract class SalesforceReadProxyRepository extends SalesforceProxyRepository
{
    /**
     * Get live data for the object (which might be empty apart from the Salesforce ID) and return a full object.
     * No need to `setSalesforceLastPull()`, or EM `persist()` - just populate the fields specific to the object.
     *
     * @param SalesforceReadProxy $proxy
     * @return SalesforceReadProxy
     */
    abstract protected function doPull(SalesforceReadProxy $proxy): SalesforceReadProxy;

    public function pull(SalesforceReadProxy $proxy, $autoSave = true): SalesforceReadProxy
    {
        // Make sure we update existing object if passed in a partial copy and we already have that Salesforce object
        // persisted, otherwise we'll try to insert a duplicate and get an ORM crash.
        if (!$proxy->getId() && ($existingProxy = $this->findOneBy(['salesforceId' => $proxy->getSalesforceId()]))) {
            $this->logInfo('Updating ' . get_class($proxy) . ' ' . $proxy->getSalesforceId() . '...');
            $proxy = $existingProxy;
        } else {
            $this->logInfo('Creating ' . get_class($proxy) . ' ' . $proxy->getSalesforceId());
        }

        $proxy = $this->doPull($proxy);
        $proxy->setSalesforceLastPull(new DateTime('now'));
        $this->getEntityManager()->persist($proxy);
        if ($autoSave) {
            $this->getEntityManager()->flush();
        }

        $this->logInfo('Done persisting ' . get_class($proxy) . ' ' . $proxy->getSalesforceId());

        return $proxy;
    }
}
