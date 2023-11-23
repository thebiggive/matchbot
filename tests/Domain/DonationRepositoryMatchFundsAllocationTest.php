<?php

namespace Domain;

use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Application\Matching\OptimisticRedisAdapter;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\Application\Matching\ArrayMatchingStorage;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;

/**
 * Focused test class just for the part match fund allocation part of DonationRepository.
 */
class DonationRepositoryMatchFundsAllocationTest extends TestCase
{
    use ProphecyTrait;

    public function testItAllocatesZeroWhenNoMatchFundsAvailable(): void
    {
        // arrange
        $campaignFundingsRepositoryProphecy = $this->prophesize(\MatchBot\Domain\CampaignFundingRepository::class);

        $emProphecy = $this->prophesize(\Doctrine\ORM\EntityManagerInterface::class);
        $emProphecy->getRepository(CampaignFunding::class)->willReturn($campaignFundingsRepositoryProphecy->reveal());

        $emProphecy->transactional(Argument::type(\Closure::class))->will(/**
         * @param list<\Closure> $args
         * @return mixed
         */ fn(array $args) => $args[0]());
        $matchingAdapter = new OptimisticRedisAdapter(new ArrayMatchingStorage(), $emProphecy->reveal(), new NullLogger());

        $sut = new DonationRepository(
            $emProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );
        $sut->setMatchingAdapter($matchingAdapter);
        $sut->setLogger(new NullLogger());

        $campaign = new Campaign(\MatchBot\Tests\TestCase::someCharity());

        $availableFundings = [];
        $campaignFundingsRepositoryProphecy->getAvailableFundings($campaign)->willReturn($availableFundings);

        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: "any project",
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $campaign,
        );

        $emProphecy->persist($donation)->shouldBeCalled();
        $emProphecy->flush()->shouldBeCalled();

        // act
        $amountMatched = $sut->allocateMatchFunds($donation);

        // assert
        $this->assertSame('0.0', $amountMatched);
    }
}