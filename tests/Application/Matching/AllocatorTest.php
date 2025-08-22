<?php

namespace MatchBot\Tests\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Tests\Application\DonationTestDataTrait;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;

class AllocatorTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

    /** @var ObjectProphecy<CampaignFundingRepository>  */
    private ObjectProphecy $campaignFundingsRepositoryProphecy;

    private Campaign $campaign;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $emProphecy;

    private Allocator $sut;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->campaignFundingsRepositoryProphecy = $this->prophesize(CampaignFundingRepository::class);

        $this->emProphecy = $this->prophesize(\Doctrine\ORM\EntityManagerInterface::class);
        $this->emProphecy->wrapInTransaction(Argument::type(\Closure::class))->will(/**
         * @param list<\Closure> $args
         * @return mixed
         */            fn(array $args) => $args[0]()
        );
        $matchingAdapter = new Adapter(
            new ArrayMatchingStorage(),
            new NullLogger(),
        );

        $this->sut = new Allocator(
            adapter: $matchingAdapter,
            entityManager: $this->emProphecy->reveal(),
            logger: new NullLogger(),
            campaignFundingRepository: $this->campaignFundingsRepositoryProphecy->reveal(),
            lockFactory: $this->createStub(LockFactory::class),
        );

        $this->campaign = \MatchBot\Tests\TestCase::someCampaign();
    }

    public function testItAllocatesZeroWhenNoMatchFundsAvailable(): void
    {
        // arrange
        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([]);

        $donation = Donation::fromApiModel(
            new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectid012345678',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
            PersonId::nil(),
        );

        // No entities to actually change but we always flush & let Doctrine check that.
        $this->emProphecy->flush()->shouldBeCalledOnce();

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
        $campaignFunding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '1',
        );
        $campaignFunding->setId(1);

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectid012345678',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
            PersonId::nil(),
        );

        $this->emProphecy->flush()->shouldBeCalled();

        // act
        $amountMatched = $this->sut->allocateMatchFunds($donation);

        // assert
        $this->assertSame('1.00', $amountMatched);
        $fundingWithdrawals = $donation->getFundingWithdrawals();

        $withdrawl = $fundingWithdrawals[0];
        $this->assertNotNull($withdrawl);
        $this->assertSame('1.00', $withdrawl->getAmount());
        $this->assertSame($campaignFunding, $withdrawl->getCampaignFunding());
    }


    /**
     * @psalm-return list<array{
     *   0: numeric-string,
     *   1: numeric-string,
     *   2: numeric-string,
     *   3: string,
     *   4: string,
     *   5: string
     * }>
     */
    public function allocationFromTwoFundingsCases(): array
    {
        // phpcs:disable
        return [
            // f0 available, f1 available, donation amount, amount matched, withdrawal0, withdrawal1,
            ['6.00', '1000000', '10', '10.00', '6.00', '4.00'],
            ['6.00', '1.00', '10', '7.00', '6.00', '1.00'],
            ['1.00', '6.00', '10', '7.00', '1.00', '6.00'],
            ['1.000000001', '6.00', '10', '7.00', '1.000000001', '6.00'],
            ['0.999999999999999999999999999999999', '6.00', '10', '6.99', '0.999999999999999999999999999999999', '6.00'],
            ['6.00', '0.999999999999999999999999999999999', '10', '6.99', '6.00', '0.999999999999999999999999999999999'],
        ];
        // phpcs:enable
    }

    /**
     * @dataProvider allocationFromTwoFundingsCases
     * @psalm-param numeric-string $funding0Available
     * @psalm-param numeric-string $funding1Available
     * @psalm-param numeric-string $donationAmount
     */
    public function testItAllocatesFromTwoFundingsFor(
        string $funding0Available,
        string $funding1Available,
        string $donationAmount,
        string $amountMatchedExpected,
        string $withdrawal0AmountExpected,
        string $withdrawl1AmountExpected
    ): void {
        $campaignFunding0 = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: $funding0Available,
        );
        $campaignFunding0->setId(0);

        $campaignFunding1 = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: $funding1Available,
        );
        $campaignFunding1->setId(1);


        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding0,
            $campaignFunding1,
        ]);
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: $donationAmount,
                projectId: 'projectid012345678',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
            PersonId::nil(),
        );

        $this->emProphecy->flush()->shouldBeCalled();

        // act
        $amountMatched = $this->sut->allocateMatchFunds($donation);

        // assert
        $this->assertSame($amountMatchedExpected, $amountMatched);
        $fundingWithdrawals = $donation->getFundingWithdrawals();

        $this->assertInstanceOf(FundingWithdrawal::class, $fundingWithdrawals[0]);
        $this->assertSame($withdrawal0AmountExpected, $fundingWithdrawals[0]->getAmount());
        $this->assertSame($campaignFunding0, $fundingWithdrawals[0]->getCampaignFunding());

        $this->assertInstanceOf(FundingWithdrawal::class, $fundingWithdrawals[1]);
        \assert(10 - 6 === 4); // @phpstan-ignore identical.alwaysTrue, function.alreadyNarrowedType
        $this->assertSame($withdrawl1AmountExpected, $fundingWithdrawals[1]->getAmount());
        $this->assertSame($campaignFunding1, $fundingWithdrawals[1]->getCampaignFunding());
    }

    public function testItAllocates1From2For2When1AlreadyMatched(): void
    {
        $campaignFunding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '1.0',
        );
        $campaignFunding->setId(1);

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '2.00',
                projectId: 'projectid012345678',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
            PersonId::nil(),
        );
        $fundingWithdrawal = new FundingWithdrawal($campaignFunding, $donation, '1.00');

        $donation->addFundingWithdrawal($fundingWithdrawal);

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
        $campaignFunding = new CampaignFunding(
            fund: new Fund('USD', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '1',
        );

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $donation = Donation::fromApiModel(
            new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectid012345678',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
            PersonId::nil(),
        );

        $this->emProphecy->flush()->shouldNotBeCalled();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Currency mismatch');

        // act
        $this->sut->allocateMatchFunds($donation);
    }

    public function testReleaseMatchFundsSuccess(): void
    {
        $matchingAdapterProphecy = $this->prophesize(Adapter::class);
        $matchingAdapterProphecy->releaseAllFundsForDonation(Argument::cetera())
            ->willReturn('0.00')
            ->shouldBeCalledOnce();

        $this->emProphecy->wrapInTransaction(Argument::type(\Closure::class))->will(/**
         * @param array<\Closure> $args
         * @return mixed
         */            fn(array $args) => $args[0]()
        );
        $this->emProphecy->flush()->shouldBeCalledOnce();

        $donation = $this->getTestDonation();

        $sut = new Allocator(
            adapter: $matchingAdapterProphecy->reveal(),
            entityManager: $this->emProphecy->reveal(),
            logger: new NullLogger(),
            campaignFundingRepository: $this->campaignFundingsRepositoryProphecy->reveal(),
            lockFactory: $this->createStub(LockFactory::class)
        );

        /** @psalm-suppress InternalMethod */
        $sut->releaseMatchFunds($donation);
    }
}
