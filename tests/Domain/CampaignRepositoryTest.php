<?php

namespace Domain;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Client\Common;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;

class CampaignRepositoryTest extends TestCase
{
    use ProphecyTrait;

    public function testItCanPullACharityFromSF(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $sut = new CampaignRepository($entityManagerProphecy->reveal(), new ClassMetadata(Campaign::class));
        $sut->setLogger(new NullLogger());

        $clientProphecy = $this->prophesize(\MatchBot\Client\Campaign::class);
        $clientProphecy->getById(Argument::any())->willReturn([
            'charity' => [
                'id' => 'id',
                'name' => 'name',
                'stripeAccountId' => 'stripeAccountId',
                'giftAidOnboardingStatus' => 'giftAidOnboardingStatus',
                'hmrcReferenceNumber' => 'hmrcReferenceNumber',
                'regulatorRegion' => 'regulatorRegion',
                'regulatorNumber' => 'regulatorNumber',
            ],
        ]);

        $sut->setClient($clientProphecy->reveal());

        $charity = \MatchBot\Tests\TestCase::someCharity();
        $sut->pull($charity);

    }
}