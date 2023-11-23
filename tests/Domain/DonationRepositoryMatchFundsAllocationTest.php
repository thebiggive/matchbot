<?php

namespace Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Application\Matching\OptimisticRedisAdapter;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\Application\Matching\ArrayMatchingStorage;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;

/**
 * Focused test class just for the part match fund allocation part of DonationRepository.
 */
class DonationRepositoryMatchFundsAllocationTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<CampaignFundingRepository>  */
    private ObjectProphecy $campaignFundingsRepositoryProphecy;

    private Campaign $campaign;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $emProphecy;

    private DonationRepository $sut;

    public function setUp(): void
    {
        parent::setUp();

        $this->campaignFundingsRepositoryProphecy = $this->prophesize(CampaignFundingRepository::class);

        $this->emProphecy = $this->prophesize(\Doctrine\ORM\EntityManagerInterface::class);
        $this->emProphecy->getRepository(CampaignFunding::class)->willReturn($this->campaignFundingsRepositoryProphecy->reveal());

        $this->emProphecy->transactional(Argument::type(\Closure::class))->will(/**
         * @param list<\Closure> $args
         * @return mixed
         */ fn(array $args) => $args[0]());
        $matchingAdapter = new OptimisticRedisAdapter(new ArrayMatchingStorage(), $this->emProphecy->reveal(), new NullLogger());

        $this->sut = new DonationRepository(
            $this->emProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );
        $this->sut->setMatchingAdapter($matchingAdapter);
        $this->sut->setLogger(new NullLogger());

        $this->campaign = new Campaign(\MatchBot\Tests\TestCase::someCharity());
    }

    public function testItAllocatesZeroWhenNoMatchFundsAvailable(): void
    {
        // arrange
        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([]);

        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: "any project",
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
        );

        $this->emProphecy->persist($donation)->shouldBeCalled();
        $this->emProphecy->flush()->shouldBeCalled();

        // act
        $amountMatched = $this->sut->allocateMatchFunds($donation);

        // assert
        $this->assertSame('0.0', $amountMatched);
    }

    /**
     * If the funds have £1 available, and the donation is for £1, then there should be £1 allocated.
     */
    public function testItAllocates1From1For1(): void
    {
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setCurrencyCode('GBP');
        $campaignFunding->setAmountAvailable('1.0');

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $this->emProphecy->persist($campaignFunding)->shouldBeCalled();
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: "any project",
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
        );

        $this->emProphecy->persist($donation)->shouldBeCalled();
        $this->emProphecy->flush()->shouldBeCalled();

        // act
        $amountMatched = $this->sut->allocateMatchFunds($donation);

        // assert
        $this->assertSame('1.00', $amountMatched);
        $fundingWithdrawals = $donation->getFundingWithdrawals();

        $withdrawl = $fundingWithdrawals[0];
        $this->assertNotNull($withdrawl);
        $this->assertSame('1.00', $withdrawl->getAmount());
    }

    public function testItRejectsFundingInWrongCurrency(): void
    {
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setCurrencyCode('USD');
        $campaignFunding->setAmountAvailable('1.0');

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: "any project",
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
        );

        $this->emProphecy->flush()->shouldNotBeCalled();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Currency mismatch');

        // act
        $this->sut->allocateMatchFunds($donation);
    }
}