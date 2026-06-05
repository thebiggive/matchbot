<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

// @mago-expect analysis:write-only-property
/**
 * @psalm-suppress UnusedProperty Various fields to be used soon.
 */
#[ORM\Entity]
class CampaignLocation extends Model
{
    /**
     * Cascade delete needed for e.g. convenient integration test resets.
     */
    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'locations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    /**
     * @var string|null Set when the country overall is on the campaign's list.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $countryName = null;

    /**
     * @var string|null ONS code, set when campaigns in the UK have specified regional focus.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $regionCode = null;

    public function __construct(Campaign $campaign, ?string $countryName, ?string $regionCode)
    {
        if ($countryName === null && $regionCode === null) {
            throw new \LogicException('At least one of countryName or regionCode must be set');
        }

        $this->campaign = $campaign;
        $this->countryName = $countryName;
        $this->regionCode = $regionCode;
    }

    /**
     * @return array{countryName: string|null, regionCode: string|null}
     */
    public function toApi(): array
    {
        return [
            'countryName' => $this->countryName,
            'regionCode' => $this->regionCode,
        ];
    }
}
