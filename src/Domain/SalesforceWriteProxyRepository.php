<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Collections\Criteria;
use MatchBot\Application\Commands\PushDonations;

/**
 * @template T of SalesforceWriteProxy
 * @template-extends SalesforceProxyRepository<T>
 */
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
        $this->save($proxy);

        if ($isNew) {
            $success = $this->doCreate($proxy);
        } elseif (empty($proxy->getSalesforceId())) {
            // We've been asked to update an object before we have confirmation back from Salesforce that
            // it was created in the first place. This is a bit different from the 'pending-additional-update'
            // double-update scenario above, since we need a Salesforce ID before any update can succeed and
            // so must 'go back' a step. This has happened rarely when Salesforce failed to save the object
            // on initial creation.
            // To avoid unnecessary risk we only create-then-update in one hop if the object is not brand
            // new, to reduce the chance of double creation in Salesforce if another thread is already
            // working on a push.
            if ($proxy->isStable()) {
                $this->logWarning(sprintf(
                    'Create-updating %s %s due to a likely past error',
                    get_class($proxy),
                    $proxy->getId(),
                ));

                if ($this->doCreate($proxy)) { // Sets SF ID ready for update.
                    $success = $this->doUpdate($proxy);
                } else {
                    $success = false; // Create failed again -> still no SF ID -> don't try to update for now.
                }
            } else {
                // Leave state unchanged -> proxy should be deemed old/stable enough on the next
                // scheduled re-try to trigger a create-then-update as per above logic branch.
                $this->logError(sprintf(
                    'Not create-updating new %s %s (missing Salesforce ID, probably errored earlier)',
                    get_class($proxy),
                    $proxy->getId(),
                ));
                $success = false;
            }
        } else {
            // SF ID already set as expected -> normal update scenario
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
    public function pushSalesforcePending(\DateTimeImmutable $now, int $limit = 400): int
    {
        // We don't want to push donations that were created or modified in the last 5 minutes,
        // to avoid collisions with other pushes.
        $fiveMinutesAgo = $now->modify('-5 minutes');

        /** @var SalesforceWriteProxy[] $proxiesToCreate */
        $proxiesToCreate = $this->findBy(
            ['salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE],
            ['id' => 'ASC'],
            $limit,
        );

        foreach ($proxiesToCreate as $proxy) {
            if ($proxy->getUpdatedDate() > $fiveMinutesAgo) {
                // fetching the proxy just to skip it here is a bit wasteful but the performance cost is low
                // compared to working out how to do a findBy equivalent with multiple criteria
                // (i.e. using \Doctrine\ORM\EntityRepository::matching() method)
                continue;
            }

            $this->push($proxy, true);
        }

        $proxiesToUpdate = $this->findBy(
            ['salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE],
            ['id' => 'ASC'],
            $limit,
        );

        foreach ($proxiesToUpdate as $proxy) {
            if ($proxy->getUpdatedDate() > $fiveMinutesAgo) {
                continue;
            }

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
                    '... marking for additional later push %s %d: SF ID %s',
                    get_class($proxy),
                    $proxy->getId(),
                    $proxy->getSalesforceId(),
                ));
            } else {
                $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
            }
            $this->logInfo('... pushed ' . get_class($proxy) . " {$proxy->getId()}: SF ID {$proxy->getSalesforceId()}");

            if ($shouldRePush) {
                if ($this->doUpdate($proxy)) { // Make sure *not* to call push() again to avoid doing this recursively!
                    $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
                    $this->logInfo('... plus interim updates for ' . get_class($proxy) . " {$proxy->getId()}");
                } else {
                    $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
                    $this->logError(sprintf(
                        '... with error on interim updates for %s %d',
                        get_class($proxy),
                        $proxy->getId(),
                    ));
                }
            }
        } else {
            $newStatus = $proxy->getSalesforcePushStatus() === SalesforceWriteProxy::PUSH_STATUS_CREATING
                ? SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE
                : SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE;
            $proxy->setSalesforcePushStatus($newStatus);
            $this->logWarning('... error pushing ' . get_class($proxy) . ' ' . $proxy->getId());
        }

        $this->save($proxy);
    }

    private function save(SalesforceWriteProxy $proxy): void
    {
        $this->getEntityManager()->persist($proxy);
        $this->getEntityManager()->flush();
    }
}
