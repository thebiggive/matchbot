<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @deprecated - use parent class Fund directly instead.
 */
class ChampionFund extends Fund
{
    public function __construct(string $currencyCode, string $name, ?Salesforce18Id $salesforceId)
    {
        parent::__construct(currencyCode: $currencyCode, name: $name, salesforceId:  $salesforceId, fundType: FundType::ChampionFund);
    }
}
