<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
class FundingWithdrawal extends Model
{
    use TimestampsTrait;

    /**
     * @ORM\ManyToOne(targetEntity="Donation")
     * @var Donation
     */
    protected $donation;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected $amount;
}
