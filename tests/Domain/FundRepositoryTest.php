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

        $repo->setLogger($this->getContainer()->get(LoggerInterface::class));

        $fund = new ChampionFund();

        // Quickest way to ensure this behaves like a newly-found Fund without having to partially mock / prophesise
        // `FundRepository` such that `doPull()` is a real call but `pull()` doesn't try a real DB engine lookup.
        $fund->setSalesforceId('sfFakeId987');

        $repo->updateFromSf($fund, false); // Don't auto-save as non-DB-backed tests can't persist

        $this->assertEquals('API Fund Name', $fund->getName());
    }

    public function testPullForCampaignAllNew(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $campaignFunding = null;

        // Validate that with everything new, the Doctrine EM is asked to persist the fund and campaign funding.
        $entityManagerProphecy
            ->persist(Argument::type(ChampionFund::class))
            ->shouldBeCalledTimes(2);
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldBeCalledTimes(2)
            ->will(
                /**
                 * @param array<CampaignFunding> $args
                 */
                function (array $args) use (&$campaignFunding) {
                    $campaignFunding = $args[0];
                }
            )
        ;

        $entityManagerProphecy->flush()->shouldBeCalledTimes(4);

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
            null, // No existing funds
            null
        );

        $campaign = new Campaign(charity: null);
        $campaign->setSalesforceId('sfFakeId987');

        $repo->pullForCampaign($campaign);

        $this->assertInstanceOf(CampaignFunding::class, $campaignFunding);
        $this->assertInstanceOf(\DateTime::class, $campaignFunding->getCreatedDate());
        $this->assertSame('1500', $campaignFunding->getAmount());
        $this->assertSame('GBP', $campaignFunding->getCurrencyCode());
        $this->assertSame('1500', $campaignFunding->getAmountAvailable());

        $fund = $campaignFunding->getFund();
        $this->assertInstanceOf(ChampionFund::class, $fund);
        $this->assertSame('sfFundId456', $fund->getSalesforceId());
        $this->assertSame('GBP', $fund->getCurrencyCode());
    }

    public function testPullForCampaignExistingFundButNewToCampaign(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        // Validate that with an existing fund on a new campaign, the Doctrine EM is asked to persist the
        // campaign funding newly, as well as the Fund with an updated amount.
        $entityManagerProphecy
            ->persist(Argument::type(ChampionFund::class))
            ->shouldBeCalledTimes(2);
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy->flush()->shouldBeCalledTimes(4);

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
            $this->getExistingFund(false),
            $this->getExistingFund(true),
        );

        $campaign = new Campaign(charity: null);
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
            ->shouldBeCalledTimes(2);
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy->flush()->shouldBeCalledTimes(4);

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);

        // For a non-shared fund, we expect to call `getFundingForCampaign()` to determine
        // whether there's an existing funding *specifically for the campaign*.

        $campaignFundingRepoProphecy
            ->getFundingForCampaign(
                Argument::which('getSalesforceId', 'sfFakeId987'),
                Argument::which('getSalesforceId', 'sfFundId123')
            )
            ->willReturn($this->getExistingCampaignFunding(false))
            ->shouldBeCalledOnce();

        // For a shared fund, we expect to call `getFunding()` to determine
        // whether there's an existing funding, linked to *any* campaign.
        $campaignFundingRepoProphecy
            ->getFunding(Argument::which('getSalesforceId', 'sfFundId456'))
            ->willReturn($this->getExistingCampaignFunding(true))
            ->shouldBeCalledOnce();

        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);

        // Validate that the matching adapter DOES have its `addAmount()` called inside a safe transaction
        // wrapper, and the £100 increase in match funding from £400 to £500 is reflected.
        $matchingAdapterProphecy->addAmount(Argument::cetera())
            ->willReturn('100.00') // Amount available after adjustment
            ->shouldBeCalledTimes(2);

        $repo = $this->getFundRepoPartialMock(
            $entityManagerProphecy->reveal(),
            $campaignFundingRepoProphecy->reveal(),
            $this->getFundClientForPerCampaignLookup(),
            $matchingAdapterProphecy->reveal(),
            $this->getExistingFund(false),
            $this->getExistingFund(true),
        );

        $campaign = new Campaign(charity: null);
        $campaign->setSalesforceId('sfFakeId987');

        $repo->pullForCampaign($campaign);
    }

    /**
     * In the case of a not allowed currency code change, the data update should
     * be skipped.
     */
    public function testPullForCampaignAllExistingWithBalancesUpdatedToNewCurrency(): void
    {
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $entityManagerProphecy
            ->persist(Argument::type(ChampionFund::class))
            ->shouldNotBeCalled();
        $entityManagerProphecy
            ->persist(Argument::type(CampaignFunding::class))
            ->shouldNotBeCalled();

        $entityManagerProphecy->flush()->shouldNotBeCalled();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);

        $campaignFundingRepoProphecy
            ->getFundingForCampaign(
                Argument::which('getSalesforceId', 'sfFakeId987'),
                Argument::which('getSalesforceId', 'sfFundId123')
            )
            ->shouldNotBeCalled();

        $campaignFundingRepoProphecy
            ->getFunding(Argument::which('getSalesforceId', 'sfFundId456'))
            ->willReturn($this->getExistingCampaignFunding(true))
            ->shouldNotBeCalled();

        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);
        $matchingAdapterProphecy->addAmount(Argument::cetera())
            ->shouldNotBeCalled();

        $repo = $this->getFundRepoPartialMock(
            $entityManagerProphecy->reveal(),
            $campaignFundingRepoProphecy->reveal(),
            $this->getFundClientForPerCampaignLookup('USD'), // Currency code change
            $matchingAdapterProphecy->reveal(),
            $this->getExistingFund(false),
            $this->getExistingFund(true),
            false, // No persists in this scenario
        );

        $campaign = new Campaign(charity: null);
        $campaign->setSalesforceId('sfFakeId987');

        $repo->pullForCampaign($campaign);
    }

    private function getExistingFund(bool $shared): Fund
    {
        $existingFund = new ChampionFund();
        $existingFund->setId($shared ? 456456 : 123123);
        $existingFund->setSalesforceId($shared ? 'sfFundId456' : 'sfFundId123');
        $existingFund->setSalesforceLastPull(new \DateTime());
        $existingFund->setCurrencyCode('GBP');
        $existingFund->setName($shared ? 'Test Shared Champion Fund 456' : 'Test Champion Fund 123');

        return $existingFund;
    }

    private function getExistingCampaignFunding(bool $shared): CampaignFunding
    {
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setFund($this->getExistingFund($shared));
        $campaignFunding->setAmount('400');
        $campaignFunding->setAllocationOrder(200);
        $campaignFunding->setCurrencyCode('GBP');

        return $campaignFunding;
    }

    /**
     * Currently, all test cases covering set up of funds w.r.t campaigns can use the same simulated single-fund
     * response from the Campaign API's `getFunds` endpoint.
     *
     * @link https://app.swaggerhub.com/apis/Noel/TBG-Campaigns/#/default/getFunds
     */
    private function getFundClientForPerCampaignLookup(string $currencyCode = 'GBP'): Client\Fund
    {
        $fundClientProphecy = $this->prophesize(Client\Fund::class);
        $fundClientProphecy->getForCampaign('sfFakeId987')->willReturn([
            [
                'id' => 'sfFundId123',
                'type' => 'championFund',
                'name' => 'Test Champion Fund 123',
                'currencyCode' => $currencyCode,
                'amountRaised' => '0.00',
                'totalAmount' => 500,
                'amountForCampaign' => 500,
                'logoUri' => 'https://httpbin.org/image/png',
                'isShared' => false,
            ],
            [
                'id' => 'sfFundId456',
                'type' => 'championFund',
                'name' => 'Test Shared Champion Fund 456',
                'currencyCode' => $currencyCode,
                'amountRaised' => '0.00',
                'totalAmount' => 1500,
                'amountForCampaign' => 1500,
                'logoUri' => 'https://httpbin.org/image/png',
                'isShared' => true,
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
     * @param Fund|null                 $existingFundNonShared
     * @param Fund|null                 $existingFundShared
     */
    private function getFundRepoPartialMock(
        $entityManager,
        $campaignFundingRepo,
        $fundClient,
        $matchingAdapter,
        ?Fund $existingFundNonShared,
        ?Fund $existingFundShared,
        bool $successfulPersistCase = true,
    ): FundRepository {
        $mockBuilder = $this->getMockBuilder(FundRepository::class);
        $mockBuilder->setConstructorArgs([$entityManager, new ClassMetadata(CampaignFunding::class)]);
        $mockBuilder->onlyMethods(['findOneBy']);

        $repo = $mockBuilder->getMock();

        $repo->expects($this->exactly($successfulPersistCase ? 2 : 1))
            ->method('findOneBy')
            ->willReturnCallback(fn(array $constraint) => match ($constraint) {
                ['salesforceId' => 'sfFundId123'] => $existingFundNonShared,
                ['salesforceId' => 'sfFundId456'] => $existingFundShared,
            });

        $repo->setCampaignFundingRepository($campaignFundingRepo);
        $repo->setClient($fundClient);
        $repo->setLogger(new NullLogger());
        $repo->setMatchingAdapter($matchingAdapter);

        return $repo;
    }
}
