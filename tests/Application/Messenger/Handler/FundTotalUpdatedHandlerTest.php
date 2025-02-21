<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Application\Messenger\Handler\FundTotalUpdatedHandler;
use MatchBot\Client;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class FundTotalUpdatedHandlerTest extends TestCase
{
    public function testSuccessProcessing(): void
    {
        $fund = new Fund('GBP', 'Testfund', Salesforce18Id::of('sfFundId4567890abc'), fundType: FundType::Pledge);
        $updateMessage = FundTotalUpdated::fromFund($fund);

        $fundClientProphecy = $this->prophesize(Client\Fund::class);
        $fundClientProphecy->pushAmountAvailable($updateMessage)->shouldBeCalledOnce();
        $handler = new FundTotalUpdatedHandler($fundClientProphecy->reveal());

        $handler($updateMessage);
    }
}
