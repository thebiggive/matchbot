<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Handler;

use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Application\Messenger\Handler\FundTotalUpdatedHandler;
use MatchBot\Client;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;

class FundTotalUpdatedHandlerTest extends TestCase
{
    public function testSuccessProcessing(): void
    {
        $fund = new ChampionFund('GBP', 'Testfund', Salesforce18Id::of('sfFundId4567890abc'));
        $updateMessage = FundTotalUpdated::fromFund($fund);

        $fundClientProphecy = $this->prophesize(Client\Fund::class);
        $fundClientProphecy->pushAmountAvailable($updateMessage)->shouldBeCalledOnce();
        $handler = new FundTotalUpdatedHandler($fundClientProphecy->reveal(), new NullLogger());

        $handler($updateMessage);
    }
}
