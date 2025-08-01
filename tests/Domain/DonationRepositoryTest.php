<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Assert\Assertion;
use DI\Container;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\PaymentCard;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;

class DonationRepositoryTest extends TestCase
{
    use DonationTestDataTrait;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManagerProphecy;

    #[\Override]
    public function setUp(): void
    {
        $connectionWhichUpdatesFine = $this->prophesize(Connection::class);
        $connectionWhichUpdatesFine->executeStatement(
            Argument::type('string'),
            Argument::type('array'),
        )
            ->willReturn(0);

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $this->entityManagerProphecy->getConnection()->willReturn($connectionWhichUpdatesFine->reveal());

        $salesforceIdSettingQuery = $this->prophesize(AbstractQuery::class);
        $salesforceIdSettingQuery->setParameter(Argument::type('string'), Argument::type('string'));
        $salesforceIdSettingQuery->execute();
        $this->entityManagerProphecy->createQuery(Argument::type('string'))
            ->willReturn($salesforceIdSettingQuery->reveal());

        parent::setUp();
    }

    public function testExistingPushOK(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(DonationUpserted::class))
            ->shouldBeCalledOnce()
            ->willReturn(Salesforce18Id::of('sfDonation36912345'));

        // Just confirm it doesn't throw.
        $donationRepository = $this->getRepo($donationClientProphecy);
        $donationRepository->push(DonationUpserted::fromDonation($this->getTestDonation()));
    }

    public function testExistingPush404InSandbox(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(DonationUpserted::class))
            ->shouldBeCalledOnce()
            ->willThrow(Client\NotFoundException::class);

        $donationRepository = $this->getRepo($donationClientProphecy);
        $donationRepository->push(self::someUpsertedMessage());
    }

    /**
     * Logs an error too – but for the purposes of this test just verify it doesn't crash unhandled
     * for now.
     */
    public function testPushResponseBadRequestErrorIsHandled(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(DonationUpserted::class))
            ->shouldBeCalledOnce()
            ->willThrow(Client\BadRequestException::class);

        $donationRepository = $this->getRepo($donationClientProphecy);
        $donationRepository->push(self::someUpsertedMessage());
    }

    public function testStripeAmountForCharityWithTipUsingAmex(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65', collected: false);

        $donation->setTipAmount('10.00');
        $donation->setPaymentCard(new PaymentCard(CardBrand::amex, Country::GB()));

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee ex vat = £ 31.80
        // Total fee inc vat = £ 31.80 * 1.2
        // Total fee inc vat = £ 38.16
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertSame(48_16, $donation->getAmountToDeductFractional());
        $this->assertSame(949_49, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithTipUsingUSCard(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65', collected: false);

        $donation->setTipAmount('10.00');
        $donation->setPaymentCard(new PaymentCard(CardBrand::visa, Country::fromAlpha2('US')));

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 31.80
        // Total fee inc vat = £ 38.16
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertSame(48_16, $donation->getAmountToDeductFractional());
        $this->assertSame(949_49, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithTip(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65', collected: false);

        $donation->setTipAmount('10.00');

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Total fee inc vat = 18.012
        // Amount after fee = £972.64

        // Deduct tip + fee.
        $this->assertSame(28_01, $donation->getAmountToDeductFractional());
        $this->assertSame(969_64, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityAndFeeVatWithTipAndVat(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65', collected: false);

        $donation->setTipAmount('10.00');

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee (net)  = £ 15.01
        // 20% VAT on fee   = £  3.00 (2 d.p)
        // Amount after fee = £969.64

        $this->assertSame('15.01', $donation->getCharityFee());
        $this->assertSame('3.00', $donation->getCharityFeeVat());
        // Deduct tip + fee inc. VAT.
        $this->assertSame(2_801, $donation->getAmountToDeductFractional());
        $this->assertSame(96_964, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTip(): void
    {
        $donation = $this->getTestDonation('987.65', collected: false);

        $donation->setTipAmount('0.00');

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Total fee in vcat = 18.012
        // Amount after fee = £972.64

        $this->assertSame(18_01, $donation->getAmountToDeductFractional());
        $this->assertSame(96_964, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipWhenTbgClaimingGiftAid(): void
    {
        $donation = $this->getTestDonation('987.65', collected: false);
        $donation->setTbgShouldProcessGiftAid(true);
        $donation->setTipAmount('0.00');

        // £987.65 *  1.5%  = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // £987.65 * 0.75%  = £  7.41 (3% of Gift Aid amount)
        // Total fee        = £ 22.42
        // Total fee inc vat = £ 26.904
        // Amount after fee = £965.23

        $this->assertSame(26_90, $donation->getAmountToDeductFractional());
        $this->assertSame(96_075, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipRoundingOnPointFive(): void
    {
        $donation = $this->getTestDonation('6.25', collected: false);
        $donation->setTipAmount('0.00');

        // £6.25 * 1.5% = £ 0.19 (to 2 d.p. – following normal mathematical rounding from £0.075)
        // Fixed fee    = £ 0.20
        // Total fee    = £ 0.29
        // Total fee inc vat = £ 0.348
        // After fee    = £ 5.96
        $this->assertSame(35, $donation->getAmountToDeductFractional());
        $this->assertSame(5_90, $donation->getAmountForCharityFractional());
    }

    public function testAbandonOldCancelled(): void
    {
        $this->getAppInstance();
        $container = $this->diContainer();

        $query = $this->prophesize(AbstractQuery::class);
        // Our test donation doesn't actually meet the conditions but as we're
        // mocking out the Doctrine bits anyway that doesn't matter; we just want
        // to check an update call is made when the result set is non-empty.
        $query->getResult()->willReturn([$this->getTestDonation()])
            ->shouldBeCalledOnce();

        $queryBuilderProphecy = $this->prophesize(QueryBuilder::class);
        $queryBuilderProphecy->select('d')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->from(Donation::class, 'd')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->where('d.donationStatus = :cancelledStatus')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->andWhere(Argument::type('string'))
            ->shouldBeCalledTimes(2)->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->orderBy('d.createdAt', 'ASC')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->setParameter(Argument::type('string'), Argument::any())
            ->shouldBeCalledTimes(3)->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->getQuery()->willReturn($query->reveal());

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->createQueryBuilder()->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $repo = new DoctrineDonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );

        $this->assertSame(1, $repo->abandonOldCancelled());
    }

    public function testFindReadyToClaimGiftAid(): void
    {
        // This needs a local var so it can be used both to set up the `Query::getResult()` prophecy
        // and for verifying the `findReadyToClaimGiftAid()` return value, without e.g. creation
        // timestamp varying.
        $testDonation = $this->getTestDonation();

        $query = $this->prophesize(AbstractQuery::class);
        // Our test donation doesn't actually meet the conditions but as we're
        // mocking out the Doctrine bits anyway that doesn't matter; we just want
        // to check an update call is made when the result set is non-empty.
        $query->getResult()->willReturn([$testDonation])
            ->shouldBeCalledOnce();

        $queryBuilderProphecy = $this->prophesize(QueryBuilder::class);
        $queryBuilderProphecy->select('d')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->from(Donation::class, 'd')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->innerJoin('d.campaign', 'campaign')->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->innerJoin('campaign.charity', 'charity')->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->where('d.donationStatus = :claimGiftAidWithStatus')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());

        // 6 `andWhere()`s in all, excluding the first `where()` but including the one
        // NOT for `$withResends` calls.
        $queryBuilderProphecy->andWhere(Argument::type('string'))
            ->shouldBeCalledTimes(6)->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->orderBy('charity.id', 'ASC')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->addOrderBy('d.collectedAt', 'ASC') ->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());

        // 2 param sets.
        $queryBuilderProphecy->setParameter('claimGiftAidWithStatus', DonationStatus::Paid->value)
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->setParameter('claimGiftAidForDonationsBefore', Argument::type(\DateTime::class))
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());

        $queryBuilderProphecy->getQuery()->willReturn($query->reveal());

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->createQueryBuilder()->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());

        $repo = new DoctrineDonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );

        $this->assertSame(
            [$testDonation],
            $repo->findReadyToClaimGiftAid(false),
        );
    }

    public function testFindWithTransferIdInArray(): void
    {
        // This needs a local var so it can be used both to set up the `Query::getResult()` prophecy
        // and for verifying the `findWithTransferIdInArray()` return value, without e.g. creation
        // timestamp varying.
        $testDonation = $this->getTestDonation();

        $query = $this->prophesize(AbstractQuery::class);
        // Our test donation doesn't actually meet the conditions but as we're
        // mocking out the Doctrine bits anyway that doesn't matter; we just want
        // to check an update call is made when the result set is non-empty.
        $query->getResult()->willReturn([$testDonation])
            ->shouldBeCalledOnce();

        $queryBuilderProphecy = $this->prophesize(QueryBuilder::class);
        $queryBuilderProphecy->select('d')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->from(Donation::class, 'd')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->where('d.transferId IN (:transferIds)')
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());
        $queryBuilderProphecy->setParameter('transferIds', ['tr_id_from_test_donation'])
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());

        $queryBuilderProphecy->getQuery()->willReturn($query->reveal());

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->createQueryBuilder()->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());

        $repo = new DoctrineDonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );

        $this->assertSame(
            [$testDonation],
            $repo->findWithTransferIdInArray(['tr_id_from_test_donation']),
        );
    }

    /**
     * @param ObjectProphecy<Client\Donation> $donationClientProphecy
     */
    private function getRepo(?ObjectProphecy $donationClientProphecy = null): DonationRepository
    {
        if (!$donationClientProphecy) {
            $donationClientProphecy = $this->prophesize(Client\Donation::class);
        }

        $entityManagerProphecy = $this->entityManagerProphecy;

        $repo = new DoctrineDonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );
        $repo->setClient($donationClientProphecy->reveal());
        $repo->setLogger(new NullLogger());

        return $repo;
    }
}
