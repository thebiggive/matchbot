<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table()
 */
class Fund
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
     * @ORM\Column(type="string", length=8)
     * @var string  'champion' or 'pledger'. Avoiding enum because of drawbacks noted at
     *              @link https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/cookbook/mysql-enums.html
     */
    protected $fundType;

    // TODO amount

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $salesforceLastUpdate;
}
