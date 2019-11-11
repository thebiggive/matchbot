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
    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var DateTime
     */
    protected $salesforceLastPush;

    /**
     * @ORM\Column(type="string")
     * @var string  One of 'not-sent', 'pending-create', 'pending-update' or 'complete'
     */
    protected $salesforcePushStatus = 'not-sent';

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
     * @param string $salesforcePushStatus
     */
    public function setSalesforcePushStatus(string $salesforcePushStatus): void
    {
        $this->salesforcePushStatus = $salesforcePushStatus;
    }
}
