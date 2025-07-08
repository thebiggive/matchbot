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
    #[ORM\Column(nullable: true)]
    protected ?DateTime $salesforceLastPull = null;

    public function setSalesforceLastPull(\DateTimeInterface $salesforceLastPull): void
    {
        $this->salesforceLastPull = DateTime::createFromInterface($salesforceLastPull);
    }

    public function getSalesforceLastPull(): ?\DateTimeImmutable
    {
        if ($this->salesforceLastPull === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($this->salesforceLastPull);
    }
}
