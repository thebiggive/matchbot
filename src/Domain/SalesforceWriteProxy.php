<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * Base for domains where MatchBot is the authoritative data source and pushes to Salesforce.
 *
 * @see SalesforceReadProxy
 */
abstract class SalesforceWriteProxy extends SalesforceProxy
{
    use TimestampsTrait;

    /** @var string Object has not yet been sent to Salesforce or queued to be so. */
    public const PUSH_STATUS_NOT_SENT = 'not-sent';
    /** @var string Object should be created in Salesforce. This might be imminent or queued. */
    public const PUSH_STATUS_PENDING_CREATE = 'pending-create';
    /** @var string Object is in the process of being created in Salesforce. */
    public const PUSH_STATUS_CREATING = 'creating';
    /** @var string Object should be updated in Salesforce. This might be imminent or queued. */
    public const PUSH_STATUS_PENDING_UPDATE = 'pending-update';
    /**
     * @var string  Object was being updated in Salesforce at the time we received potentially new
     *              data, so we returned the second request early without re-pushing immediately.
     *              This status should be maintained temporarily until the original push finishes,
     *              at which point it will know from this to set the status back to
     *              'pending-update' rather than 'complete'.
     */
    public const PUSH_STATUS_PENDING_ADDITIONAL_UPDATE = 'pending-additional-update';
    /**
     * @var string  Object is in the process of being updated in Salesforce. Any further updates
     *              should be queued as they're liable to lead to record contention issues if
     *              attempted in parallel.
     */
    public const PUSH_STATUS_UPDATING = 'updating';
    /** @var string Object has been created and/or updated in Salesforce and no push is pending. */
    public const PUSH_STATUS_COMPLETE = 'complete';
    /**
     * @var string  Object has been marked as non-existent on the Salesforce side. This should only
     *              happen outside Production and is typically due to sandbox refreshes clearing data.
     */
    public const PUSH_STATUS_REMOVED = 'removed';

    /**
     * @return bool Whether the entity has been modified in MatchBot subsequently after its creation.
     */
    abstract public function hasPostCreateUpdates(): bool;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTime $salesforceLastPush = null;

    /**
     * @ORM\Column(type="string")
     * @var string  One of 'not-sent', 'pending-create', 'creating', 'pending-update',
     *              'pending-additional-update', 'updating', 'complete' or 'removed'.
     *              Use class constants above to guard against typos and improve inline
     *              documentation where we use these.
     */
    protected string $salesforcePushStatus = self::PUSH_STATUS_NOT_SENT;

    /**
     * @return DateTime
     */
    public function getSalesforceLastPush(): ?DateTime
    {
        return $this->salesforceLastPush;
    }

    /**
     * @param DateTime $salesforceLastPush
     */
    public function setSalesforceLastPush(DateTime $salesforceLastPush): void
    {
        $this->salesforceLastPush = $salesforceLastPush;
    }

    /**
     * @return string
     */
    public function getSalesforcePushStatus(): string
    {
        return $this->salesforcePushStatus;
    }

    /**
     * @psalm-param self::PUSH_STATUS_* $salesforcePushStatus
     * @param string $salesforcePushStatus
     */
    public function setSalesforcePushStatus(string $salesforcePushStatus): void
    {
        $this->salesforcePushStatus = $salesforcePushStatus;
    }

    /**
     * Indicates whether the proxy's local object was created & persisted at least 30 seconds
     * ago, and so is deemed old enough to be likely stable enough for us to e.g. assume that
     * another thread is not still trying to do a first push up to Salesforce.
     *
     * @return bool Whether object is stable/old enough.
     */
    public function isStable(): bool
    {
        return $this->createdAt !== null && $this->createdAt < (new DateTime('-30 seconds'));
    }
}
