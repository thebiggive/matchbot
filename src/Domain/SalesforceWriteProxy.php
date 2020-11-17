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
     * @return bool Whether the entity has been modified in MatchBot subsequently after its creation.
     */
    abstract public function hasPostCreateUpdates(): bool;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTime $salesforceLastPush = null;

    /**
     * @ORM\Column(type="string")
     * @var string  One of 'not-sent', 'pending-create', 'pending-update', 'complete' or 'removed'.
     */
    protected string $salesforcePushStatus = 'not-sent';

    /**
     * @return DateTime
     */
    public function getSalesforceLastPush(): ?DateTime
    {
        return $this->salesforceLastPush;
    }

    /**
     * @return DateTime
     */
    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return string
     */
    public function getDonationStatus(): ?string
    {
        return $this->donationStatus;
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
