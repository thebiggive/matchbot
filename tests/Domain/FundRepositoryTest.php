<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Application\Matching;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundRepository;
use MatchBot\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FundRepositoryTest extends TestCase
{
    public function testPull(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $fundClientProphecy = $this->prophesize(Client\Fund::class);
        $fundClientProphecy
            ->getById('sfFakeId987')
            ->shouldBeCalledOnce()
            ->willReturn([
                'name' => 'API Fund Name',
                'totalAmount' => '123.45',
            ]);

        $repo = new FundRepository($entityManagerProphecy->reveal(), new ClassMetadata(Fund::class));
        $repo->setClient($fundClientProphecy->reveal());

        $repo->setLogger($this->getAppInstance()->getContainer()->get(LoggerInterface::class));

        $fund = new ChampionFund();

        // Quickest way to ensure this behaves like a newly-found Fund without having to partially mock / prophesise
        // `FundRepository` such that `doPull()` is a real call but `pull()` doesn't try a real DB engine lookup.
        $fund->setId(987);

        $fund->setSalesforceId('sfFakeId987');

        $fund = $repo->pull($fund, false); // Don't auto-save as non-DB-backed tests can't persist

        $this->assertEquals('API Fund Name', $fund->getName());
        $this->assertEquals('123.45', $fund->getAmount());
    }

    public function testPullForCampaignAllNew(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        // Validate that with everything new, the Doctrine EM is asked to persist the fund and campaign funding.
        $entityManagerProphecy
            ->persist(Argument::type(ChampionFund::class))
            ->shouldBeCalledOnce();
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldBeCalledOnce();

        // This is not mututally exclusive with the above call expectations. It's a quick way to double check
        // that both persists are setting their respective object's amount to £500, even when the pre-existing
        // Fund we simulate had a £400 total balance before.
        $entityManagerProphecy
            ->persist(Argument::which('getAmount', '500'))
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy->flush()->shouldBeCalledTimes(2); // One flush after each of the above persists.

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);

        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);
        // Validate that the matching adapter does NOT have its `addAmount()` called in the case where the fund is
        // brand new and so an initial amount can be safely set on the Doctrine object with `setAmountAvailable()`.
        $matchingAdapterProphecy
            ->addAmount(Argument::type(CampaignFunding::class), Argument::type('string'))
            ->shouldNotBeCalled();

        $repo = $this->getFundRepoPartialMock(
            $entityManagerProphecy->reveal(),
            $campaignFundingRepoProphecy->reveal(),
            $this->getFundClientForPerCampaignLookup(),
            $matchingAdapterProphecy->reveal(),
            null // No existing fund
        );

        $campaign = new Campaign();
        $campaign->setSalesforceId('sfFakeId987');

        $repo->pullForCampaign($campaign);
    }

    public function testPullForCampaignExistingFundButNewToCampaign(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        // Validate that with an existing fund on a new campaign, the Doctrine EM is asked to persist the
        // campaign funding newly, as well as the Fund with an updated amount.
        $entityManagerProphecy
            ->persist(Argument::type(ChampionFund::class))
            ->shouldBeCalledOnce();
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldBeCalledOnce();

        // This is not mututally exclusive with the above call expectations. It's a quick way to double check
        // that both persists are setting their respective object's amount to £500, even when the pre-existing
        // Fund we simulate had a £400 total balance before.
        $entityManagerProphecy
            ->persist(Argument::which('getAmount', '500'))
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy->flush()->shouldBeCalledTimes(2); // One flush after each of the above persists.

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);

        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);
        // Validate that the matching adapter does NOT have its `addAmount()` called in the case where the fund is
        // brand new and so an initial amount can be safely set on the Doctrine object with `setAmountAvailable()`.
        $matchingAdapterProphecy
            ->addAmount(Argument::type(CampaignFunding::class), Argument::type('string'))
            ->shouldNotBeCalled();

        $repo = $this->getFundRepoPartialMock(
            $entityManagerProphecy->reveal(),
            $campaignFundingRepoProphecy->reveal(),
            $this->getFundClientForPerCampaignLookup(),
            $matchingAdapterProphecy->reveal(),
            $this->getExistingFund(),
        );

        $campaign = new Campaign();
        $campaign->setSalesforceId('sfFakeId987');

        $repo->pullForCampaign($campaign);
    }

    public function testPullForCampaignAllExistingWithBalanceUpdated(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        // Validate that with an existing fund on an existing campaign, the Doctrine EM is asked to persist the
        // campaign funding and Fund, with updated amounts.
        $entityManagerProphecy
            ->persist(Argument::type(ChampionFund::class))
            ->shouldBeCalledOnce();
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldBeCalledOnce();

        // This is not mututally exclusive with the above call expectations. It's a quick way to double check
        // that both persists are setting their respective object's amount to £500, even when the pre-existing
        // Fund we simulate had a £400 total balance before.
        $entityManagerProphecy
            ->persist(Argument::which('getAmount', '500'))
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy->flush()->shouldBeCalledTimes(2); // One flush after each of the two persists.

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy
            ->getFunding(Argument::which('getSalesforceId', 'sfFundId123'))
            ->willReturn($this->getExistingCampaignFunding())
            ->shouldBeCalledOnce();

        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);
        // Validate that the matching adapter DOES have its `addAmount()` called and the £100 increase in
        // match funding from £400 to £500 is reflected.
        $matchingAdapterProphecy
            ->addAmount(Argument::type(CampaignFunding::class), '100')
            ->shouldBeCalledOnce();

        $repo = $this->getFundRepoPartialMock(
            $entityManagerProphecy->reveal(),
            $campaignFundingRepoProphecy->reveal(),
            $this->getFundClientForPerCampaignLookup(),
            $matchingAdapterProphecy->reveal(),
            $this->getExistingFund(),
        );

        $campaign = new Campaign();
        $campaign->setSalesforceId('sfFakeId987');

        $repo->pullForCampaign($campaign);
    }

    private function getExistingFund(): Fund
    {
        $existingFund = new ChampionFund();
        $existingFund->setSalesforceId('sfFundId123');
        $existingFund->setSalesforceLastPull(new \DateTime());
        $existingFund->setAmount('400');
        $existingFund->setName('Test Champion Fund 123');

        return $existingFund;
    }

    private function getExistingCampaignFunding(): CampaignFunding
    {
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setFund($this->getExistingFund());
        $campaignFunding->setAmount('400');
        $campaignFunding->setAllocationOrder(200);

        return $campaignFunding;
    }

    /**
     * Currently, all test cases covering set up of funds w.r.t campaigns can use the same simulated single-fund
     * response from the Campaign API's `getFunds` endpoint.
     *
     * @link https://app.swaggerhub.com/apis/Noel/TBG-Campaigns/#/default/getFunds
     *
     * @return Client\Fund|ObjectProphecy
     */
    private function getFundClientForPerCampaignLookup(): Client\Fund
    {
        $fundClientProphecy = $this->prophesize(Client\Fund::class);
        $fundClientProphecy->getForCampaign('sfFakeId987')->willReturn([
            [
                'id' => 'sfFundId123',
                'type' => 'championFund',
                'name' => 'Test Champion Fund 123',
                'amountRaised' => '0.00',
                'totalAmount' => 500,
                'amountForCampaign' => 500,
                'logoUri' => 'https://httpbin.org/image/png',
            ],
        ]);

        return $fundClientProphecy->reveal();
    }

    /**
     * Because we need to mock `findOneBy()` in this test (there's no DB engine against which real lookups can
     * succeed, and the method is supplied by Doctrine & not 'owned' by MatchBot), and also need to make a real
     * call to the same object's method `pullForCampaign()` to validate its behaviour, this test class is a rare
     * case where we used *partial* mocks instead of Prophecy to stub out only the method we must fake.
     *
     * @param EntityManagerInterface    $entityManager
     * @param CampaignFundingRepository $campaignFundingRepo
     * @param Client\Fund               $fundClient
     * @param Matching\Adapter          $matchingAdapter
     * @param Fund|null                 $existingFund
     * @return FundRepository|MockObject
     */
    private function getFundRepoPartialMock(
        $entityManager,
        $campaignFundingRepo,
        $fundClient,
        $matchingAdapter,
        ?Fund $existingFund
    ): FundRepository {
        $mockBuilder = $this->getMockBuilder(FundRepository::class);
        $mockBuilder->setConstructorArgs([$entityManager, new ClassMetadata(CampaignFunding::class)]);
        $mockBuilder->onlyMethods(['findOneBy']);

        $repo = $mockBuilder->getMock();

        $repo->expects($this->once())
            ->method('findOneBy')
            ->with(['salesforceId' => 'sfFundId123'])
            ->willReturn($existingFund);

        $repo->setCampaignFundingRepository($campaignFundingRepo);
        $repo->setClient($fundClient);
        $repo->setLogger(new NullLogger());
        $repo->setMatchingAdapter($matchingAdapter);

        return $repo;
    }
}
