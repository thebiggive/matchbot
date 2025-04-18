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
     * @psalm-suppress PossiblyUnusedProperty - used in DB queries
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTime $salesforceLastPull = null;

    /**
     * @param DateTime $salesforceLastPull
     */
    public function setSalesforceLastPull(DateTime $salesforceLastPull): void
    {
        $this->salesforceLastPull = $salesforceLastPull;
    }
}
