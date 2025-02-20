<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Pledge extends Fund
{
    public const int NORMAL_ALLOCATION_ORDER = 100;
}
