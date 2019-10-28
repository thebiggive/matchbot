<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
class Fund extends SalesforceProxy
{
    use TimestampsTrait;

    /**
     * @ORM\Column(type="string", length=8)
     * @var string  'champion' or 'pledger'. Avoiding enum because of drawbacks noted at
     *              @link https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/cookbook/mysql-enums.html
     */
    protected $fundType;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected $amount;
}
