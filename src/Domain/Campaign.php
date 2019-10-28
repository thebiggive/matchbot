<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table()
 */
class Campaign
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var int
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=18, unique=true)
     * @var string
     */
    protected $salesforceId;

    /**
     * @ORM\ManyToOne(targetEntity="Charity")
     * @var Charity
     */
    protected $charity;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $name;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $salesforceLastUpdate;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $startDate;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $endDate;
}
