<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
class Charity extends SalesforceProxy
{
    use TimestampsTrait;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $name;
}
