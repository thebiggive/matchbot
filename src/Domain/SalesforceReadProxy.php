<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * Base for domains where Salesforce is the authoritative data source and MatchBot pulls from Salesforce.
 *
 * @see SalesforceWriteProxy
 */
abstract class SalesforceReadProxy extends SalesforceProxy
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
