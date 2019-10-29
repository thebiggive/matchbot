<?php

declare(strict_types=1);

namespace MatchBot\Domain;

class FundRepository extends SalesforceProxyRepository
{
    public function doPull(SalesforceProxy $fund): SalesforceProxy
    {
        if ($fund instanceof ChampionFund) {
            return $this->pullChampionFund($fund);
        }

        if ($fund instanceof Pledge) {
            return $this->pullPledge($fund);
        }

        throw new \UnexpectedValueException('Can only pull ChampionFund or Pledge from Salesforce');
    }

    private function pullChampionFund(ChampionFund $fund)
    {
        // todo
    }

    private function pullPledge(SalesforceProxy $fund)
    {
        // todo
    }
}
