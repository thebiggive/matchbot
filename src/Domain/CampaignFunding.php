<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table()
 */
class CampaignFunding
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var int
     */
    protected $id;

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

    // TODO appropriate money field for `amount`

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
