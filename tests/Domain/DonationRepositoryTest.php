<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use DI\Container;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\SalesforceWriteProxy;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\Application\VatTrait;
use MatchBot\Tests\TestCase;
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
    use VatTrait;

    public function testExistingPushOK(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn(true);

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertTrue($success);
    }

    public function testItReplacesNullDonationTypeWithCardOnUpdate(): void
    {
        // arrange
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy->put(Argument::any())->willReturn(true);
        $sut = $this->getRepo($donationClientProphecy);

        // Simulate an old donation that was created in OCtober 22 or earlier,
        // before we forced every donation to have a Payment Method Type set.
        // May want to make the property non-nullalble but will require updating DB records.
        $donation = $this->getTestDonation();
        $paymentMethodTypeProperty = new \ReflectionProperty(Donation::class, 'paymentMethodType');
        $paymentMethodTypeProperty->setValue($donation, null);

        // act
        $sut->doUpdate($donation);

        // assert
        $this->assertSame(PaymentMethodType::Card, $donation->getPaymentMethodType());
    }

    public function testExistingButPendingNotRePushed(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $pendingDonation = $this->getTestDonation();
        $pendingDonation->setDonationStatus(DonationStatus::Pending);
        $success = $this->getRepo($donationClientProphecy)->push($pendingDonation, false);

        $this->assertTrue($success);
    }

    /**
     * This is expected e.g. after a Salesforce network failure leading to a missing ID for
     * a donation, but the create completed and the donor can proceed. This may lead to
     * webhooks trying to update the record, setting its push status to 'pending-update',
     * before it has a proxy ID set.
     *
     * If this happens we should treat it like a new record to un-stick things.
     *
     * @link https://thebiggive.atlassian.net/browse/MAT-170
     */
    public function testExistingPushWithMissingProxyIdButPendingUpdateStatusStable(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->create(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn(true);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn(true);

        $donation = $this->getTestDonation();
        $donationReflected = new ReflectionClass($donation);

        $createdAtProperty = $donationReflected->getProperty('createdAt');
        $createdAtProperty->setValue($donation, new \DateTime('-31 seconds'));

        $sfIdProperty = $donationReflected->getProperty('salesforceId');
        $sfIdProperty->setValue($donation, null); // Allowed property type but not allowed in public setter.

        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
        $success = $this->getRepo($donationClientProphecy)->push($donation, false);

        // We let push() handle both steps for older-than-30s donations, without waiting for a new process.
        $this->assertTrue($success);
        $this->assertEquals('complete', $donation->getSalesforcePushStatus());
    }

    public function testExistingPushWithMissingProxyIdButPendingUpdateStatusNew(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->create(Argument::type(Donation::class))
            ->shouldNotBeCalled();
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldNotBeCalled();

        $donation = $this->getTestDonation();
        $donationReflected = new ReflectionClass($donation);

        $sfIdProperty = $donationReflected->getProperty('salesforceId');
        $sfIdProperty->setValue($donation, null); // Allowed property type but not allowed in public setter.

        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
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
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willThrow(Client\NotFoundException::class);

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertTrue($success);
    }

    public function testBuildFromApiRequestSuccess(): void
    {
        $dummyCampaign = new Campaign(charity: \MatchBot\Tests\TestCase::someCharity());
        $dummyCampaign->setCurrencyCode('USD');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(Argument::type('array'))
            ->willReturn($dummyCampaign)
            ->shouldBeCalledOnce();

        $createPayload = new DonationCreate(
            currencyCode: 'USD',
            donationAmount: '123.32',
            pspMethodType: PaymentMethodType::Card,
            projectId: 'testProject123',
            psp: 'stripe',
        );

        $donation = $this->getRepo(null, false, $campaignRepoProphecy)
            ->buildFromApiRequest($createPayload);

        $this->assertEquals('USD', $donation->getCurrencyCode());
        $this->assertEquals('123.32', $donation->getAmount());
        $this->assertEquals(12_332, $donation->getAmountFractionalIncTip());
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
            donationAmount: '144.44',
            pspMethodType: PaymentMethodType::Card,
            projectId: 'testProject123',
            psp: 'stripe',
        );

        $this->getRepo(null, false, $campaignRepoProphecy)
            ->buildFromApiRequest($createPayload);
    }

    public function testPushResponseError(): void
    {
        $donationClientProphecy = $this->prophesize(Client\Donation::class);
        $donationClientProphecy
            ->put(Argument::type(Donation::class))
            ->shouldBeCalledOnce()
            ->willReturn(false);

        $success = $this->getRepo($donationClientProphecy)->push($this->getTestDonation(), false);

        $this->assertFalse($success);
    }

    public function testStripeAmountForCharityWithTipUsingAmex(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');;
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');
        $this->getRepo()->deriveFees($donation, 'amex', null);

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 31.80
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertEquals(4_180, $donation->getAmountToDeductFractional());
        $this->assertEquals(95_585, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithTipUsingUSCard(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');;
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');
        $this->getRepo()->deriveFees($donation, 'visa', 'US');

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 31.80
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertEquals(4_180, $donation->getAmountToDeductFractional());
        $this->assertEquals(95_585, $donation->getAmountForCharityFractional());
    }

    /**
     * Alt fee model campaign + fee cover selected.
     */
    public function testStripeAmountForCharityWithFeeCover(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');;
        $donation->setTipAmount('0.00');
        $donation->setPsp('stripe');
        $donation->setFeeCoverAmount('44.44'); // 4.5% fee, inc. any VAT.
        $donation->getCampaign()->setFeePercentage(4.5);
        $this->getRepo()->deriveFees($donation, null, null);

        // £987.65 * 4.5%   = £ 44.44 (to 2 d.p.)
        // Fixed fee        = £  0.00
        // Total fee        = £ 44.44 – ADDED in this case, not taken from charity
        // Amount after fee = £987.65

        // Deduct *only* the TBG tip / fee cover.
        $this->assertEquals(4_444, $donation->getAmountToDeductFractional());
        $this->assertEquals(98_765, $donation->getAmountForCharityFractional());
        // £987.65 + £44.44 fee cover = £1,032.09.
        $this->assertEquals(103_209, $donation->getAmountFractionalIncTip());
    }

    public function testStripeAmountForCharityWithTip(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');;
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');
        $this->getRepo()->deriveFees($donation, null, null);

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Amount after fee = £972.64

        // Deduct tip + fee.
        $this->assertEquals(2_501, $donation->getAmountToDeductFractional());
        $this->assertEquals(97_264, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityAndFeeVatWithTipAndVat(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = $this->getTestDonation('987.65');;
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');

        // Get repo with 20% VAT enabled from now setting override.
        $this->getRepo(null, true)->deriveFees($donation, null, null);

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
        $donation = $this->getTestDonation('987.65');;
        $donation->setPsp('stripe');
        $donation->setTipAmount('0.00');
        $this->getRepo()->deriveFees($donation, null, null);

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Amount after fee = £972.64

        $this->assertEquals(1_501, $donation->getAmountToDeductFractional());
        $this->assertEquals(97_264, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipWhenTbgClaimingGiftAid(): void
    {
        $donation = $this->getTestDonation('987.65');
        $donation->setTbgShouldProcessGiftAid(true);
        $donation->setPsp('stripe');
        $donation->setTipAmount('0.00');
        $this->getRepo()->deriveFees($donation, null, null);

        // £987.65 *  1.5%  = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // £987.65 * 0.75%  = £  7.41 (3% of Gift Aid amount)
        // Total fee        = £ 22.42
        // Amount after fee = £965.23

        $this->assertEquals(2_242, $donation->getAmountToDeductFractional());
        $this->assertEquals(96_523, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipRoundingOnPointFive(): void
    {
        $donation = $this->getTestDonation('6.25');
        $donation->setPsp('stripe');
        $donation->setTipAmount('0.00');
        $this->getRepo()->deriveFees($donation, null, null);

        // £6.25 * 1.5% = £ 0.19 (to 2 d.p. – following normal mathematical rounding from £0.075)
        // Fixed fee    = £ 0.20
        // Total fee    = £ 0.29
        // After fee    = £ 5.96
        $this->assertEquals(29, $donation->getAmountToDeductFractional());
        $this->assertEquals(596, $donation->getAmountForCharityFractional());
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
        $matchingAdapterProphecy->runTransactionally(Argument::type('callable'))
            ->willReturn('0.00')
            ->shouldBeCalledOnce();

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
        $matchingAdapterProphecy->runTransactionally(Argument::type('callable'))
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
        $matchingAdapterProphecy->runTransactionally(Argument::type('callable'))
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
        $repo->setSettings($this->getAppInstance()->getContainer()->get('settings'));

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
        $repo->setSettings($this->getAppInstance()->getContainer()->get('settings'));

        $this->assertEquals(
            [$testDonation],
            $repo->findWithTransferIdInArray(['tr_externalId_123']),
        );
    }

    /**
     * @param ObjectProphecy|null   $donationClientProphecy
     * @param bool                  $vatLive    Whether to override config with 20% VAT live from now.
     * @return DonationRepository
     * @throws \Exception
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

        $settings = $this->getAppInstance()->getContainer()->get('settings');
        if ($vatLive) {
            $settings = $this->getUKLikeVATSettings($settings);
        }

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $repo = new DonationRepository(
            $entityManagerProphecy->reveal(),
            new ClassMetadata(Donation::class),
        );
        $repo->setClient($donationClientProphecy->reveal());
        $repo->setLogger(new NullLogger());
        $repo->setSettings($settings);

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
