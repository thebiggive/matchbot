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
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $salesforceLastPull = null;

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
