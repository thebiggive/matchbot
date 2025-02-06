<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Client;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends the amount of a Fund used for Salesforce to record after a campaign. (Affects Pledge__c
 * and ChampionFunding__c custom Salesforce objects.)
 *
 * Also includes currency code and total so that Salesforce can check these are in sync before
 * saving anything.
 */
#[AsMessageHandler]
readonly class FundTotalUpdatedHandler
{
    public function __construct(private Client\Fund $fundClient)
    {
    }

    public function __invoke(FundTotalUpdated $message): void
    {
        $this->fundClient->pushAmountAvailable($message);
    }
}
