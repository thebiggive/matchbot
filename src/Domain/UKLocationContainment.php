<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Represents the relationship between a UK location and a larger (or identical) UK location that contains it.
 *
 * To be populated in a migration based on hard-coded data so we can join to it queries, not updated as the application
 * runs.
 *
 * @psalm-suppress UnusedClass
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-suppress PossiblyUnusedMethod
 */
#[ORM\Index(name: 'regionCode', columns: ['regionCode'])]
#[ORM\Index(name: 'ancestorRegionCode', columns: ['ancestorRegionCode'])]
#[ORM\Entity]
class UKLocationContainment extends Model
{
    use TimestampsTrait;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(string $regionCode, string $ancestorRegionCode)
    {
        $this->regionCode = $regionCode;
        $this->ancestorRegionCode = $ancestorRegionCode;

        $this->createdNow();
    }

    /**
     * ONS Code of this region
     */
    #[ORM\Column(length: 10)]
    private(set) string $regionCode;

    /**
     * ONS codes of all containing regions (including this region itself)
     *
     */
    #[ORM\Column(length: 10)]
    private(set) string $ancestorRegionCode;
}
