<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Client;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;

class DonationRepositoryTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

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

    public function testBuildFromApiRequestWithCurrencyMismatch(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Currency CAD is invalid for campaign');

        $dummyCampaign = new Campaign();
        $dummyCampaign->setCurrencyCode('USD');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        // No change – campaign still has a charity without a Stripe Account ID.
        $campaignRepoProphecy->findOneBy(Argument::type('array'))
            ->willReturn($dummyCampaign)
            ->shouldBeCalledOnce();

        $createPayload = new DonationCreate();
        $createPayload->currencyCode = 'CAD';
        $createPayload->projectId = 'testProject123';
        $createPayload->psp = 'stripe';

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

    public function testEnthuseAmountForCharityWithTipWithoutGiftAid(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('enthuse');
        $donation->setTipAmount('10.00');
        $donation = $this->getRepo()->deriveFees($donation);

        // £987.65 * 1.9%   = £ 18.77 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 18.97
        // Amount after fee = £968.68

        // Deduct tip + fee.
        $this->assertEquals(2_897, $donation->getAmountToDeductFractional());
        $this->assertEquals(96_868, $donation->getAmountForCharityFractional());
    }

    public function testEnthuseAmountForCharityWithTipAndGiftAid(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('enthuse');
        $donation->setTipAmount('10.00');
        $donation->setGiftAid(true);
        $donation = $this->getRepo()->deriveFees($donation);

        // £987.65 * 1.9%   = £ 18.77 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Txn amount fee   = £ 18.97
        // Fee on Gift Aid  = £  9.88
        // Amount after fee = £958.80

        // Deduct both fee types, and tip.
        $this->assertEquals(3_885, $donation->getAmountToDeductFractional());
        $this->assertEquals(95_880, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithTipUsingAmex(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');
        $donation = $this->getRepo()->deriveFees($donation, 'amex');

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
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');
        $donation = $this->getRepo()->deriveFees($donation, 'visa', 'US');

        // £987.65 * 3.2%   = £ 31.60 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 31.80
        // Amount after fee = £955.85

        // Deduct tip + fee.
        $this->assertEquals(4_180, $donation->getAmountToDeductFractional());
        $this->assertEquals(95_585, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithTip(): void
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');
        $donation = $this->getRepo()->deriveFees($donation);

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
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('stripe');
        $donation->setTipAmount('10.00');

        // Get repo with 20% VAT enabled from now setting override.
        $donation = $this->getRepo(null, true)->deriveFees($donation);

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
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setPsp('stripe');
        $donation = $this->getRepo()->deriveFees($donation);

        // £987.65 * 1.5%   = £ 14.81 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 15.01
        // Amount after fee = £972.64

        $this->assertEquals(1_501, $donation->getAmountToDeductFractional());
        $this->assertEquals(97_264, $donation->getAmountForCharityFractional());
    }

    public function testStripeAmountForCharityWithoutTipRoundingOnPointFive(): void
    {
        $donation = new Donation();
        $donation->setPsp('stripe');
        $donation->setAmount('6.25');
        $donation = $this->getRepo()->deriveFees($donation);

        // £6.25 * 1.5% = £ 0.19 (to 2 d.p. – following normal mathematical rounding from £0.075)
        // Fixed fee    = £ 0.20
        // Total fee    = £ 0.29
        // After fee    = £ 5.96
        $this->assertEquals(29, $donation->getAmountToDeductFractional());
        $this->assertEquals(596, $donation->getAmountForCharityFractional());
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
    ): DonationRepository {
        if (!$donationClientProphecy) {
            $donationClientProphecy = $this->prophesize(Client\Donation::class);
        }

        $settings = $this->getAppInstance()->getContainer()->get('settings');
        if ($vatLive) {
            $settings['stripe']['fee']['vat_percentage_live'] = '20';
            $settings['stripe']['fee']['vat_percentage_old'] = '0';
            $settings['stripe']['fee']['vat_live_date'] = (new \DateTime())->format('c');
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

        return $repo;
    }
}
