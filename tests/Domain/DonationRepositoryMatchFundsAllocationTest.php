<?php

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Pledge;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use MatchBot\Tests\Application\Matching\ArrayMatchingStorage;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\RoutableMessageBus;

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
        $this->emProphecy->getRepository(CampaignFunding::class)
            ->willReturn($this->campaignFundingsRepositoryProphecy->reveal());

        $this->emProphecy->wrapInTransaction(Argument::type(\Closure::class))->will(/**
         * @param list<\Closure> $args
         * @return mixed
         */            fn(array $args) => $args[0]()
        );
        $matchingAdapter = new Adapter(
            new ArrayMatchingStorage(),
            $this->emProphecy->reveal(),
            new NullLogger(),
        );

        $this->sut = new DonationRepository(
            $this->emProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );
        $this->sut->setMatchingAdapter($matchingAdapter);
        $this->sut->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
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
                projectId: 'projectid012345678',
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
        $campaignFunding = new CampaignFunding(
            fund: new Pledge('GBP', 'some pledge'),
            amount: '1000',
            amountAvailable: '1',
            allocationOrder: 100
        );
        $campaignFunding->setId(1);

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $this->emProphecy->persist($campaignFunding)->shouldBeCalled();
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectid012345678',
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
            fund: new Pledge('GBP', 'some pledge'),
            amount: '1000',
            amountAvailable: $funding0Available,
            allocationOrder: 100
        );
        $campaignFunding0->setId(0);

        $campaignFunding1 = new CampaignFunding(
            fund: new Pledge('GBP', 'some pledge'),
            amount: '1000',
            amountAvailable: $funding1Available,
            allocationOrder: 100
        );
        $campaignFunding1->setId(1);


        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding0,
            $campaignFunding1,
        ]);
        $this->emProphecy->persist($campaignFunding0)->shouldBeCalled();
        $this->emProphecy->persist($campaignFunding1)->shouldBeCalled();
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: $donationAmount,
                projectId: 'projectid012345678',
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
        $this->assertSame($amountMatchedExpected, $amountMatched);
        $fundingWithdrawals = $donation->getFundingWithdrawals();

        $this->assertInstanceOf(FundingWithdrawal::class, $fundingWithdrawals[0]);
        $this->assertSame($withdrawal0AmountExpected, $fundingWithdrawals[0]->getAmount());
        $this->assertSame($campaignFunding0, $fundingWithdrawals[0]->getCampaignFunding());

        $this->assertInstanceOf(FundingWithdrawal::class, $fundingWithdrawals[1]);
        \assert(10 - 6 == 4);
        $this->assertSame($withdrawl1AmountExpected, $fundingWithdrawals[1]->getAmount());
        $this->assertSame($campaignFunding1, $fundingWithdrawals[1]->getCampaignFunding());
    }

    public function testItAllocates1From2For2When1AlreadyMatched(): void
    {
        $campaignFunding = new CampaignFunding(
            fund: new Pledge('GBP', 'some pledge'),
            amount: '1000',
            amountAvailable: '1.0',
            allocationOrder: 100
        );
        $campaignFunding->setId(1);

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $this->emProphecy->persist($campaignFunding)->shouldBeCalled();
        $this->emProphecy->persist(Argument::type(FundingWithdrawal::class))->shouldBeCalled();

        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '2.00',
                projectId: 'projectid012345678',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card
            ),
            $this->campaign,
        );
        $fundingWithdrawal = new FundingWithdrawal($campaignFunding);
        $fundingWithdrawal->setAmount('1.00');
        $donation->addFundingWithdrawal($fundingWithdrawal);

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
        $campaignFunding = new CampaignFunding(
            fund: new Pledge('USD', 'some pledge'),
            amount: '1000',
            amountAvailable: '1',
            allocationOrder: 100
        );

        $this->campaignFundingsRepositoryProphecy->getAvailableFundings($this->campaign)->willReturn([
            $campaignFunding
        ]);
        $donation = Donation::fromApiModel(
            new \MatchBot\Application\HttpModels\DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '1',
                projectId: 'projectid012345678',
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
