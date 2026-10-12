<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Name with Entity is not good, named like that because of conflict with the existing non database linked UKLocation
 * class.
 *
 * Represents a location in the UK based on ONS data, with a full list of its containing locations.
 *
 * To be populated in a migration based on hard-coded data so we can join to it queries, not updated as the application
 * runs.
 *
 * @psalm-suppress UnusedClass
 */
class UKLocationEntity extends Model
{
    use TimestampsTrait;

    /**
     * @param string $regionCode
     * @param list<string> $ancestorRegionCodes
     */
    public function __construct(string $regionCode, string $name, array $ancestorRegionCodes)
    {
        $this->regionCode = $regionCode;
        $this->name = $name;
        $this->ancestorRegionCodes = $ancestorRegionCodes;

        $this->createdNow();
    }

    /**
     * ONS Code of this region
     */
    #[ORM\Column(length: 10)]
    private(set) string $regionCode;

    /**
     * ONS Code of this region
     */
    #[ORM\Column(length: 255)]
    private(set) string $name;

    /**
     * ONS codes of all containing regions (including this region itself)
     *
     * @var list<string>
     *
     */
    #[ORM\Column(type: 'json')]
    private(set) array $ancestorRegionCodes;
}
