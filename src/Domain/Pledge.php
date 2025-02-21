<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Normal Pledges are used before {@see ChampionFund}s.
 * @see TopupPledge for the distinct type of pledge that is sometimes committed above a pledge target.
 */
#[ORM\Entity]
class Pledge extends Fund
{
    public const int NORMAL_ALLOCATION_ORDER = 100;
}
