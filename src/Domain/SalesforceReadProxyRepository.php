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
    // TODO I don't think a general purpose repo pull method makes sense, it's diverging too much across models
    // What we probably DO want is a single entity method to update itself, which can throw if ever called
    // when it doesn't support independent single-item updates (e.g. Charity)

    public function pull(SalesforceReadProxy $proxy, $autoSave = true): SalesforceReadProxy
    {
        // Make sure we update existing object if passed in a partial copy and we already have that Salesforce object
        // persisted, otherwise we'll try to insert a duplicate and get an ORM crash.
        if (!$proxy->getId() && ($existingProxy = $this->findOneBy(['salesforceId' => $proxy->getSalesforceId()]))) {
            $proxy = $existingProxy;
        }

        $proxy = $this->doPull($proxy);
        $proxy->setSalesforceLastPull(new DateTime('now'));
        $this->getEntityManager()->persist($proxy);
        if ($autoSave) {
            $this->getEntityManager()->flush();
        }

        return $proxy;
    }
}
