<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @see SalesforceReadProxy
 * @see SalesforceWriteProxy
 */
abstract class SalesforceProxy extends Model
{
    /**
     * @ORM\Column(type="string", length=18, unique=true, nullable=true)
     * @var string|null Nullable because write proxies may be created before the first Salesforce push
     */
    protected ?string $salesforceId = null;

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
}
