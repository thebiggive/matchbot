<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Pledge extends Fund
{
    public const DISCRIMINATOR_VALUE = 'pledge';
}
