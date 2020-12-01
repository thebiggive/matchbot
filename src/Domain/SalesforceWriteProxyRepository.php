<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use MatchBot\Application\Commands\PushDonations;

abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    abstract public function doCreate(SalesforceWriteProxy $proxy): bool;
    abstract public function doUpdate(SalesforceWriteProxy $proxy): bool;

    public function push(SalesforceWriteProxy $proxy, bool $isNew): bool
    {
        // This 'pre-`prePush()`' check protects us from trying to save the same Salesforce record twice at once.
        if (!$isNew && $proxy->getSalesforcePushStatus() === SalesforceWriteProxy::PUSH_STATUS_UPDATING) {
            /**
             * If we were in the middle of awaiting Salesforce processing of an update
             * and are asked to push another one, do nothing except flag that this happened.
             * We can return immediately and then:
             * * the already in-flight update will finish up by setting the status back to
             *   'pending-update' instead of 'complete' – see postPush().
             * * the scheduled PushDonations command will pick up pushing the data on a delay.
             *   In the rare case that the new update contained different data, it will get
             *   synced to Salesforce at the point.
             * @see SalesforceWriteProxyRepository::postPush()
             * @see PushDonations
             */
            $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_ADDITIONAL_UPDATE);
            $this->logInfo('Queued extra update for ' . get_class($proxy) . ' ' . $proxy->getId());

            // This is the best we can do in this scenario while awaiting the first Salesforce response,
            // and by returning early we ensure postPush() is not called. So as a summary return value
            // we can safely treat this as successful.
            return true;
        }

        $this->logInfo(($isNew ? 'Pushing ' : 'Updating ') . get_class($proxy) . ' ' . $proxy->getId() . '...');
        $this->prePush($proxy, $isNew);

        $proxy->setSalesforcePushStatus(
            $isNew ? SalesforceWriteProxy::PUSH_STATUS_CREATING : SalesforceWriteProxy::PUSH_STATUS_UPDATING
        );
        $this->getEntityManager()->persist($proxy);
        $this->getEntityManager()->flush();

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
            ['salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE],
            ['id' => 'ASC'],
            $limit,
        );

        foreach ($proxiesToCreate as $proxy) {
            $this->push($proxy, true);
        }

        $proxiesToUpdate = $this->findBy(
            ['salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE],
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
            $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE);
        } else {
            $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
        }
    }

    protected function postPush(bool $success, SalesforceWriteProxy $proxy): void
    {
        $shouldRePush = false;
        if ($success) {
            $proxy->setSalesforceLastPush(new DateTime('now'));
            if (
                $proxy->getSalesforcePushStatus() === SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE &&
                $proxy->hasPostCreateUpdates()
            ) {
                $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
                $shouldRePush = true;
            } elseif (
                $proxy->getSalesforcePushStatus() ===
                SalesforceWriteProxy::PUSH_STATUS_PENDING_ADDITIONAL_UPDATE
            ) {
                $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
                $this->logInfo(sprintf(
                    '...marking for additional later push %s %d: SF ID %s',
                    get_class($proxy),
                    $proxy->getId(),
                    $proxy->getSalesforceId(),
                ));
            } else {
                $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
            }
            $this->logInfo('...pushed ' . get_class($proxy) . " {$proxy->getId()}: SF ID {$proxy->getSalesforceId()}");

            if ($shouldRePush) {
                if ($this->doUpdate($proxy)) { // Make sure *not* to call push() again to avoid doing this recursively!
                    $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
                    $this->logInfo('...plus interim updates for ' . get_class($proxy) . " {$proxy->getId()}");
                } else {
                    $this->logError('...with error on interim updates for ' . get_class($proxy) . " {$proxy->getId()}");
                }
            }
        } else {
            $this->logWarning('...error pushing ' . get_class($proxy) . ' ' . $proxy->getId());
        }

        $this->getEntityManager()->persist($proxy);
        $this->getEntityManager()->flush();
    }
}
