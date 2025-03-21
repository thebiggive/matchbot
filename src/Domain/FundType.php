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
        $order = match ($this) {
            self::Pledge => 1,
            self::ChampionFund => 2,
            self::TopupPledge => 3,
        };

        // Multiply by 100 here to allow room to add more cases in between in future, while
        // allowing Infection's IncrementInteger / DecrementInteger mutators to
        // check tests cover match above.
        return $order * 100;
    }

    public function isPledge(): bool
    {
        return match ($this) {
            self::Pledge, self::TopupPledge => true,
            self::ChampionFund => false,
        };
    }
}
