<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table()
 */
class FundingWithdrawal
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var int
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="Donation")
     * @var Donation
     */
    protected $donation;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $createdDate;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $updatedDate;
    // TODO amount
}
