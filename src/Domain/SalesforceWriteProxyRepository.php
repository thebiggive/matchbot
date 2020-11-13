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
            $success = $this->doUpdate($proxy);
        }

        $this->postPush($success, $proxy);

        return $success;
    }

    /**
     * Sends proxy objects to Salesforce en masse. We set an overall limit for objects because Salesforce can
     * be quite slow to do this and we want to be pretty certain that task runs won't overlap.
     *
     * For the same reason of bulk pushes not overlapping, we can be reasonably sure Salesforce should not hit
     * record contention issues as a result of running these pushes back to back, even if they involve writing
     * updates to the same objects. Locking has generally been a problem because of requests coming in from
     * different places and Salesforce's multi-threaded but locking nature. So when we send a lot of requests
     * sequentially from *one* place in a batch process we shouldn't usually have the same problem except when
     * these pushes clash with synchronous, on-demand update attempts.
     *
     * @param int $limit    Maximum of each type of pending object to process
     * @return int  Number of objects pushed
     */
    public function pushAllPending(int $limit = 200): int
    {
        $proxiesToCreate = $this->findBy(
            ['salesforcePushStatus' => 'pending-create'],
            ['id' => 'ASC'],
            $limit,
        );
        foreach ($proxiesToCreate as $proxy) {
            $this->push($proxy, true);
        }

        $proxiesToUpdate = $this->findBy(
            ['salesforcePushStatus' => 'pending-update'],
            ['id' => 'ASC'],
            $limit,
        );
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
