<?php

namespace MatchBot\Domain;

/**
 * Different types of funds have different allocation orders.
 */
enum FundType: string
{
    /**
     * Normal Pledges are used before champion funds.
     * see TopupPledge for the distinct type of pledge that is sometimes committed above a pledge target.
     */
    case Pledge = 'pledge';

    case ChampionFund = 'championFund';

    /**
     * Top-up pledges represent commitments beyond a charity's pledge target (including when that target
     * is £0 because the campaign is 1:1 model) and are used champion funds.
     */
    case TopupPledge = 'topupPledge';

    /**
     * @return positive-int
     */
    public function allocationOrder(): int
    {
        return match ($this) {
            self::Pledge => 100,
            self::ChampionFund => 200,
            self::TopupPledge => 300,
        };
    }
}
