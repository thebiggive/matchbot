<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * Base for Domain models which are ultimately represented with Salesforce data. Without extension this class
 * gives support for 'read-only' behaviour, i.e. objects are expected to be pulled in from Salesforce and nothing
 * pushed back there. But it is also extended by SalesforceProxyReadyWrite which adds push support.
 * @see SalesforceProxyReadWrite
 */
abstract class SalesforceProxy extends Model
{
    /**
     * @ORM\Column(type="string", length=18, unique=true, nullable=true)
     * @var string  Nullable because read-write proxies may be created before the first Salesforce push
     */
    protected $salesforceId;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var DateTime    Nullable because read-write proxies might not be pulled from Salesforce
     */
    protected $salesforceLastPull;

    /**
     * @return string
     */
    public function getSalesforceId(): ?string
    {
        return $this->salesforceId;
    }

    /**
     * @param string $salesforceId
     */
    public function setSalesforceId(string $salesforceId): void
    {
        $this->salesforceId = $salesforceId;
    }

    /**
     * @return DateTime
     */
    public function getSalesforceLastPull(): ?DateTime
    {
        return $this->salesforceLastPull;
    }

    /**
     * @param DateTime $salesforceLastPull
     */
    public function setSalesforceLastPull(DateTime $salesforceLastPull): void
    {
        $this->salesforceLastPull = $salesforceLastPull;
    }
}
