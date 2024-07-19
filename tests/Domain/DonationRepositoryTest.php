<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use DI\Container;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Client;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use PHP_CodeSniffer\Standards\PSR1\Sniffs\Methods\CamelCapsMethodNameSniff;
use PhpParser\Node\Arg;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

class DonationRepositoryTest extends TestCase
{
    use DonationTestDataTrait;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManagerProphecy;

    public function setUp(): void
    {
        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        parent::setUp();
    }

    public function testExistingPushOK(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn('someNewSfId');
        $this->entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalled();
        $this->entityManagerProphecy->flush()->shouldBeCalled();

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertTrue($success);
    }

    public function testItReplacesNullDonationTypeWithCardOnUpdate(): void
    {
        // arrange
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy->createOrUpdate(Argument::any())->willReturn('someNewSfId');
        $sut = $this->getRepo($donationClientProphecy);

        // Simulate an old donation that was created in OCtober 22 or earlier,
        // before we forced every donation to have a Payment Method Type set.
        // May want to make the property non-nullalble but will require updating DB records.
        $donation = $this->getTestDonation();
        $paymentMethodTypeProperty = new \ReflectionProperty(Donation::class, 'paymentMethodType');
        $paymentMethodTypeProperty->setValue($donation, null);

        $this->entityManagerProphecy->persist($donation)->shouldBeCalled();

        // act
        $sut->doUpdate($donation);

        // assert
        $this->assertSame(PaymentMethodType::Card, $donation->getPaymentMethodType());
    }

    public function testExistingButPendingNotRePushed(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $pendingDonation = $this->getTestDonation();
        $pendingDonation->setDonationStatus(DonationStatus::Pending);
        $this->entityManagerProphecy->persist($pendingDonation)->shouldBeCalled();
        $this->entityManagerProphecy->flush()->shouldBeCalled();

        $success = $this->getRepo($donationClientProphecy)->push($pendingDonation, false);

        $this->assertTrue($success);
    }

    public function testExistingPushWithMissingProxyIdButPendingUpdateStatusNew(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $donation = $this->getTestDonation();
        $donationReflected = new ReflectionClass($donation);

        $sfIdProperty = $donationReflected->getProperty('salesforceId');
        $sfIdProperty->setValue($donation, null); // Allowed property type but not allowed in public setter.

        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);

        $this->entityManagerProphecy->persist($donation)->shouldBeCalled();
        $this->entityManagerProphecy->flush()->shouldBeCalled();

        $success = $this->getRepo($donationClientProphecy)->push($donation, false);

        // For brand new donations, push() should do nothing here and leave the donation to be picked up
        // by a later scheduled run, remaining in 'pending-update' push status.
        $this->assertFalse($success);
        $this->assertEquals('pending-update', $donation->getSalesforcePushStatus());
    }

    public function testExistingPush404InSandbox(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willThrow(Client\NotFoundException::class);

        $donation = $this->getTestDonation();

        $this->entityManagerProphecy->persist($donation)->shouldBeCalled();
        $this->entityManagerProphecy->flush()->shouldBeCalled();


        $success = $this->getRepo($donationClientProphecy)->push($donation, false);

        $this->assertTrue($success);
    }

    public function testBuildFromApiRequestSuccess(): void
    {
        $dummyCampaign = new Campaign(charity: \MatchBot\Tests\TestCase::someCharity());
        $dummyCampaign->setCurrencyCode('USD');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(['salesforceId' => 'testProject1234567'])
            ->willReturn($dummyCampaign);

        $createPayload = new DonationCreate(
            currencyCode: 'USD',
            donationAmount: '123',
            pspMethodType: PaymentMethodType::Card,
            projectId: 'testProject1234567',
            psp: 'stripe',
        );

        $donation = $this->getRepo(null, false, $campaignRepoProphecy)
            ->buildFromApiRequest($createPayload);

        $this->assertEquals('USD', $donation->getCurrencyCode());
        $this->assertEquals('123', $donation->getAmount());
        $this->assertEquals(12_300, $donation->getAmountFractionalIncTip());
    }

    public function testItPullsCampaignFromSFIfNotInRepo(): void
    {
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $fundRepositoryProphecy = $this->prophesize(FundRepository::class);
        $this->entityManagerProphecy->flush()->shouldBeCalled();

        $dummyCampaign = new Campaign(TestCase::someCharity());
        $dummyCampaign->setCurrencyCode('GBP');
        $dummyCampaign->setSalesforceId('testProject1234567');


        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(['salesforceId' => 'testProject1234567'])
            ->willReturn(null);
        $campaignRepoProphecy->pullNewFromSf(Salesforce18Id::of('testProject1234567'))->willReturn($dummyCampaign);

        $fundRepositoryProphecy->pullForCampaign(Argument::type(Campaign::class))->shouldBeCalled();

        $createPayload = new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '123',
            pspMethodType: PaymentMethodType::Card,
            projectId: 'testProject1234567',
            psp: 'stripe',
        );

        $donationRepository = $this->getRepo(
            campaignRepoProphecy: $campaignRepoProphecy,
        );
        $donationRepository->setFundRepository($fundRepositoryProphecy->reveal());

        $donation = $donationRepository
            ->buildFromApiRequest($createPayload);

        $this->assertSame('testProject1234567', $donation->getCampaign()->getSalesforceId());
    }

    public function testBuildFromApiRequestWithCurrencyMismatch(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Currency CAD is invalid for campaign');

        $dummyCampaign = new Campaign(charity: null);
        $dummyCampaign->setCurrencyCode('USD');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(Argument::type('array'))
            ->willReturn($dummyCampaign)
            ->shouldBeCalledOnce();

        $createPayload = new DonationCreate(
            currencyCode: 'CAD',
            donationAmount: '144.0',
            pspMethodType: PaymentMethodType::Card,
            projectId: 'testProject1234567',
            psp: 'stripe',
        );

        $this->getRepo(null, false, $campaignRepoProphecy)
            ->buildFromApiRequest($createPayload);
    }

    public function testPushResponseError(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->createOrUpdate(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willThrow(new BadRequestException('Some error'));

        $donation = $this->getTestDonation();

        $this->entityManagerProphecy->persist($donation)->shouldBeCalled();
        $this->entityManagerProphecy->flush()->shouldBeCalled();

        $success = $this->getRepo($donationClientProphecy)->push($donation, false);

        $this->assertFalse($success);
    }

    public function testStripeAmountForCharityWithTipUsingAmex(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');
        ;
        $donation->setTipAmount('10.00');
        $donation->deriveFees('amex', null);

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee ex vat = £ 31.80
        // Total fee inc vat = £ 31.80 * 1.2
        // Total fee inc vat = £ 38.16
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertEquals(48_16, $donation->getAmountToDeductFractional());
        $this->assertEquals(949_49, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithTipUsingUSCard(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');
        ;
        $donation->setTipAmount('10.00');
        $donation->deriveFees('visa', 'US');

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 31.80
        // Total fee inc vat = £ 38.16
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertEquals(48_16, $donation->getAmountToDeductFractional());
        $this->assertEquals(949_49, $donation->getAmountForCharityFractional());
    }

    // note we had testStripeAmountForCharityWithFeeCover() here - removed in this commit
    // as part of MAT-356, may be worth finding and putting back if/when we introduce fee cover.

    public function testStripeAmountForCharityWithTip(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');
        ;
        $donation->setTipAmount('10.00');
        $donation->deriveFees(null, null);

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Total fee inc vat = 18.012
        // Amount after fee = £972.64

        // Deduct tip + fee.
        $this->assertEquals(28_01, $donation->getAmountToDeductFractional());
        $this->assertEquals(969_64, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityAndFeeVatWithTipAndVat(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');
        ;
        $donation->setTipAmount('10.00');

        $donation->deriveFees(null, null);

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee (net)  = £ 15.01
        // 20% VAT on fee   = £  3.00 (2 d.p)
        // Amount after fee = £969.64

        $this->assertEquals('15.01', $donation->getCharityFee());
        $this->assertEquals('3.00', $donation->getCharityFeeVat());
        // Deduct tip + fee inc. VAT.
        $this->assertEquals(2_801, $donation->getAmountToDeductFractional());
        $this->assertEquals(96_964, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTip(): void
    {
        $donation = $this->getTestDonation('987.65');
        ;
        $donation->setTipAmount('0.00');
        $donation->deriveFees(null, null);

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Total fee in vcat = 18.012
        // Amount after fee = £972.64

        $this->assertEquals(18_01, $donation->getAmountToDeductFractional());
        $this->assertEquals(96_964, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipWhenTbgClaimingGiftAid(): void
    {
        $donation = $this->getTestDonation('987.65');
        $donation->setTbgShouldProcessGiftAid(true);
        $donation->setTipAmount('0.00');
        $donation->deriveFees(null, null);

        // £987.65 *  1.5%  = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // £987.65 * 0.75%  = £  7.41 (3% of Gift Aid amount)
        // Total fee        = £ 22.42
        // Total fee inc vat = £ 26.904
        // Amount after fee = £965.23

        $this->assertEquals(26_90, $donation->getAmountToDeductFractional());
        $this->assertEquals(96_075, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipRoundingOnPointFive(): void
    {
        $donation = $this->getTestDonation('6.25');
        $donation->setTipAmount('0.00');
        $donation->deriveFees(null, null);

        // £6.25 * 1.5% = £ 0.19 (to 2 d.p. – following normal mathematical rounding from £0.075)
        // Fixed fee    = £ 0.20
        // Total fee    = £ 0.29
        // Total fee inc vat = £ 0.348
        // After fee    = £ 5.96
        $this->assertEquals(35, $donation->getAmountToDeductFractional());
        $this->assertEquals(5_90, $donation->getAmountForCharityFractional());
    }

    public function testReleaseMatchFundsSuccess(): void
    {
        $lockProphecy = $this->prophesize(LockInterface::class);
        $lockProphecy->acquire(false)->willReturn(true)->shouldBeCalledOnce();
        $lockProphecy->release()->shouldBeCalledOnce();

        $lockFactoryProphecy = $this->prophesize(LockFactory::class);
        $lockFactoryProphecy->createLock(Argument::type('string'))
            ->willReturn($lockProphecy->reveal())
            ->shouldBeCalledOnce();

        $matchingAdapterProphecy = $this->prophesize(Adapter::class);
        $matchingAdapterProphecy->releaseAllFundsForDonation(Argument::cetera())
            ->willReturn('0.00')
            ->shouldBeCalledOnce();

        $this->entityManagerProphecy->transactional(Argument::type(\Closure::class))->will(/**
         * @param array<\Closure> $args
         * @return mixed
         */            fn(array $args) => $args[0]()
        );

        $repo = $this->getRepo(
            null,
            false,
            null,
            $matchingAdapterProphecy,
            $lockFactoryProphecy,
        );

        $donation = $this->getTestDonation();
        $repo->releaseMatchFunds($donation);
    }

    public function testReleaseMatchFundsLockNotAcquired(): void
    {
        $lockProphecy = $this->prophesize(LockInterface::class);
        $lockProphecy->acquire(false)->willReturn(false)->shouldBeCalledOnce();
        $lockProphecy->release()->shouldNotBeCalled();

        $lockFactoryProphecy = $this->prophesize(LockFactory::class);
        $lockFactoryProphecy->createLock(Argument::type('string'))
            ->willReturn($lockProphecy->reveal())
            ->shouldBeCalledOnce();

        $matchingAdapterProphecy = $this->prophesize(Adapter::class);
        $matchingAdapterProphecy->subtractAmountWithoutSavingToDB(Argument::cetera())
            ->shouldNotBeCalled();

        $repo = $this->getRepo(
            null,
            false,
            null,
            $matchingAdapterProphecy,
            $lockFactoryProphecy,
        );

        $donation = $this->getTestDonation();
        $repo->releaseMatchFunds($donation);
    }

    public function testReleaseMatchFundsLockHitsAcquiringException(): void
    {
        $lockProphecy = $this->prophesize(LockInterface::class);
        $lockProphecy->acquire(false)
            ->willThrow(LockAcquiringException::class)
            ->shouldBeCalledOnce();
        $lockProphecy->release()->shouldNotBeCalled();

        $lockFactoryProphecy = $this->prophesize(LockFactory::class);
        $lockFactoryProphecy->createLock(Argument::type('string'))
            ->willReturn($lockProphecy->reveal())
            ->shouldBeCalledOnce();

        $matchingAdapterProphecy = $this->prophesize(Adapter::class);
        $matchingAdapterProphecy->subtractAmountWithoutSavingToDB(Argument::cetera())
            ->shouldNotBeCalled();

        $repo = $this->getRepo(
            null,
            false,
            null,
            $matchingAdapterProphecy,
            $lockFactoryProphecy,
        );

        $donation = $this->getTestDonation();
        $repo->releaseMatchFunds($donation);
    }

    public function testAbandonOldCancelled(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

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
        $repo = new DonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );

        $this->assertEquals(1, $repo->abandonOldCancelled());
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

        $repo = new DonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );

        $this->assertEquals(
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
        $queryBuilderProphecy->setParameter('transferIds', ['tr_externalId_123'])
            ->shouldBeCalledOnce()->willReturn($queryBuilderProphecy->reveal());

        $queryBuilderProphecy->getQuery()->willReturn($query->reveal());

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->createQueryBuilder()->shouldBeCalledOnce()
            ->willReturn($queryBuilderProphecy->reveal());

        $repo = new DonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );

        $this->assertEquals(
            [$testDonation],
            $repo->findWithTransferIdInArray(['tr_externalId_123']),
        );
    }

    /**
     * @param ObjectProphecy<Client\Donation> $donationClientProphecy
     * @param ObjectProphecy<Adapter> $matchingAdapterProphecy
     * @param ObjectProphecy<LockFactory> $lockFactoryProphecy
     * @param ObjectProphecy<CampaignRepository> $campaignRepoProphecy
     */
    private function getRepo(
        ?ObjectProphecy $donationClientProphecy = null,
        bool $vatLive = false,
        ?ObjectProphecy $campaignRepoProphecy = null,
        ?ObjectProphecy $matchingAdapterProphecy = null,
        ?ObjectProphecy $lockFactoryProphecy = null,
    ): DonationRepository {
        if (!$donationClientProphecy) {
            $donationClientProphecy = $this->prophesize(Client\Donation::class);
        }

        $configurationProphecy = $this->prophesize(\Doctrine\ORM\Configuration::class);
        $config = $configurationProphecy->reveal();
        $configurationProphecy->getResultCacheImpl()->willReturn($this->createStub(CacheProvider::class));

        $entityManagerProphecy = $this->entityManagerProphecy;

        $entityManagerProphecy->getConfiguration()->willReturn($config);
        $repo = new DonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );
        $repo->setClient($donationClientProphecy->reveal());
        $repo->setLogger(new NullLogger());

        if ($campaignRepoProphecy) {
            $repo->setCampaignRepository($campaignRepoProphecy->reveal());
        }

        if ($matchingAdapterProphecy) {
            $repo->setMatchingAdapter($matchingAdapterProphecy->reveal());
        }

        if ($lockFactoryProphecy) {
            $repo->setLockFactory($lockFactoryProphecy->reveal());
        }

        return $repo;
    }
}
