<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;

abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    abstract public function doCreate(SalesforceWriteProxy $proxy): bool;
    abstract public function doUpdate(SalesforceWriteProxy $proxy): bool;

    public function push(SalesforceWriteProxy $proxy, bool $isNew): bool
    {
        $this->logInfo(($isNew ? 'Pushing ' : 'Updating ') . get_class($proxy) . ' ' . $proxy->getId() . '...');
        $this->prePush($proxy, $isNew);

        if ($isNew) {
            $success = $this->doCreate($proxy);
        } elseif (empty($proxy->getSalesforceId())) {
            // We've been asked to update an object before we have confirmation back from Salesforce that
            // it was created in the first place. There's no way this can work - we need the Salesforce
            // ID to identify what we're updating - so log an error and ensure the object is left in
            // 'pending-create' state locally for a scheduled task to try again at pushing its full current
            // local state.
            $this->logError("Can't update " . get_class($proxy) . " {$proxy->getId()} without a Salesforce ID");
            $success = false;
        } else {
            if ($proxy instanceof Donation) {
                $this->logInfo("Tip debug: {$proxy->getUuid()} tip amount pre refresh: {$proxy->getTipAmount()}");
            }
            $this->getEntityManager()->refresh($proxy);
            if ($proxy instanceof Donation) {
                $this->logInfo("Tip debug: {$proxy->getUuid()} tip amount postrefresh: {$proxy->getTipAmount()}");
            }

            $success = $this->doUpdate($proxy);
        }

        $this->postPush($success, $proxy);

        return $success;
    }

    /**
     * @return int  Number of objects pushed
     */
    public function pushAllPending(): int
    {
        $proxiesToCreate = $this->findBy(['salesforcePushStatus' => 'pending-create']);
        foreach ($proxiesToCreate as $proxy) {
            $this->push($proxy, true);
        }

        $proxiesToUpdate = $this->findBy(['salesforcePushStatus' => 'pending-update']);
        foreach ($proxiesToUpdate as $proxy) {
            $this->push($proxy, false);
        }

        return count($proxiesToCreate) + count($proxiesToUpdate);
    }

    protected function prePush(SalesforceWriteProxy $proxy, bool $isNew): void
    {
        if ($isNew || empty($proxy->getSalesforceId())) {
            $proxy->setSalesforcePushStatus('pending-create');
        } else {
            $proxy->setSalesforcePushStatus('pending-update');
        }
    }

    protected function postPush(bool $success, SalesforceWriteProxy $proxy): void
    {
        if ($success) {
            $proxy->setSalesforceLastPush(new DateTime('now'));
            if ($proxy->getSalesforcePushStatus() === 'pending-create' && $proxy->hasPostCreateUpdates()) {
                $proxy->setSalesforcePushStatus('pending-update');
            } else {
                $proxy->setSalesforcePushStatus('complete');
            }
            $this->logInfo('...pushed ' . get_class($proxy) . " {$proxy->getId()}: SF ID {$proxy->getSalesforceId()}");

            if ($proxy->hasPostCreateUpdates()) {
                if ($this->doUpdate($proxy)) { // Make sure *not* to call push() again to avoid doing this recursively!
                    $proxy->setSalesforcePushStatus('complete');
                    $this->logInfo('...plus interim updates for ' . get_class($proxy) . " {$proxy->getId()}");
                } else {
                    $this->logError('...with error on interim updates for ' . get_class($proxy) . " {$proxy->getId()}");
                }
            }
        } else {
            $this->logError('...error pushing ' . get_class($proxy) . ' ' . $proxy->getId());
        }

        $this->getEntityManager()->persist($proxy);
        $this->getEntityManager()->flush();
    }
}
