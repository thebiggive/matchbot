<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use MatchBot\Client;

class FundRepository extends SalesforceReadProxyRepository
{
    public function doPull(SalesforceReadProxy $fund): SalesforceReadProxy
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

    private function pullPledge(SalesforceReadProxy $fund)
    {
        // todo
    }

    protected function getClient(): Client\Common
    {
        // TODO: Implement getClient() method.
    }
}
