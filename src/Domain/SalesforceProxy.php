<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @see SalesforceReadProxy
 * @see SalesforceWriteProxy
 */
#[ORM\Index(name: 'salesforceId', columns: ['salesforceId'])]
abstract class SalesforceProxy extends Model
{
    /**
     * @deprecated - treat as private, access via getters and setters unless you know the instance
     * is already loaded from the DB, to allow Doctrine Proxies to do their lazy-loading thing.
     *
     * Actually setting as private breaks use of this property by the ORM for child classes.
     *
     * @var string|null Nullable because write proxies may be created before the first Salesforce push
     */
    #[ORM\Column(length: 18, unique: true, nullable: true)]
    protected ?string $salesforceId = null;

    /**
     * @return string
     */
    public function getSalesforceId(): ?string
    {
        /** @psalm-suppress DeprecatedProperty */
        return $this->salesforceId;
    }

    /**
     * @param string $salesforceId
     */
    public function setSalesforceId(string $salesforceId): void
    {
        /** @psalm-suppress DeprecatedProperty */
        $this->salesforceId = $salesforceId;
    }
}
