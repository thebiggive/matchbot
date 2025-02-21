<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Top-up pledges represent commitments beyond a charity's pledge target (including when that target
 * is £0 because the campaign is 1:1 model) and are used *after* {@see ChampionFund}s.
 */
#[ORM\Entity]
class TopupPledge extends Fund
{
    public const int NORMAL_ALLOCATION_ORDER = 300;
}
