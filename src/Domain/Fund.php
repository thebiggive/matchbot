<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="fundType", type="string")
 * @ORM\DiscriminatorMap({"championFund" = "ChampionFund", "pledge" = "Pledge", "unknownFund" = "Fund"})
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
abstract class Fund extends SalesforceProxy
{
    use TimestampsTrait;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected $amount;
}
