<?php

namespace MatchBot\Tests\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Application\Messenger\CharityUpdated;
use MatchBot\Application\Messenger\Handler\CharityUpdatedHandler;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;

class CharityUpdatedHandlerTest extends TestCase
{
    use ProphecyTrait;

    private Salesforce18Id $charityId;

    public function setUp(): void
    {
        $this->charityId = Salesforce18Id::ofCharity('charity89012345678');
    }

    public function testItCallsUpdateFromSf(): void
    {
        $message = new CharityUpdated($this->charityId);
        $onlyRelevantCampaign = self::someCampaign();

        $campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepositoryProphecy->findUpdatableForCharity($this->charityId)
            ->willReturn([$onlyRelevantCampaign])
            ->shouldBeCalledOnce();

        // Besides confirming the handler doesn't crash, this is the point of the test as it stands.
        // Given that the above repo helper had a campaign, we expect `updateFromSf()` which calls
        // out to the API to also be called.
        $campaignRepositoryProphecy->updateFromSf($onlyRelevantCampaign)->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $sut = new CharityUpdatedHandler(
            $entityManagerProphecy->reveal(),
            Environment::fromAppEnv('test'),
            new NullLogger(),
            $campaignRepositoryProphecy->reveal()
        );

        $sut->__invoke($message);
    }
}
