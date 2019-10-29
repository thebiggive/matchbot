<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
class CampaignFunding extends Model
{
    use TimestampsTrait;

    /**
     * @ORM\ManyToMany(targetEntity="Campaign")
     * @var Campaign[]
     */
    protected $campaigns;

    /**
     * @ORM\ManyToOne(targetEntity="Fund")
     * @var Fund
     */
    protected $fund;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected $amount;

    /**
     * @ORM\Column(type="integer")
     * @var int     Order of preference as a rank, i.e. lower numbers have their funds used first.
     */
    protected $order;

    public function isShared(): bool
    {
        return (count($this->campaigns) > 1);
    }
}
