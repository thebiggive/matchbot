<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ChampionFund extends Fund
{
    public const string DISCRIMINATOR_VALUE = 'championFund';
}
