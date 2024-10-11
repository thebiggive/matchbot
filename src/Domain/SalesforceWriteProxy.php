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
    /** @var string Object should be created in Salesforce. This might be imminent or queued. */
    public const string PUSH_STATUS_PENDING_CREATE = 'pending-create';
    /** @var string Object should be updated in Salesforce. This might be imminent or queued. */
    public const string PUSH_STATUS_PENDING_UPDATE = 'pending-update';
    /**
     * @var string  Object has been created and/or updated in Salesforce and no push is pending. Includes some cases
     *              where there is just no applicable data to send.
     */
    public const string PUSH_STATUS_COMPLETE = 'complete';

    /**
     * @psalm-suppress PossiblyUnusedProperty   Used for manual dev database checks.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $salesforceLastPush = null;

    /**
     * @var string  One of 'pending-create', 'pending-update',
     *              or 'complete' or 'removed'.
     *              Use class constants above to guard against typos and improve inline
     *              documentation where we use these.
     */
    #[ORM\Column(type: 'string')]
    protected string $salesforcePushStatus = self::PUSH_STATUS_PENDING_CREATE;

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
}
