<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Client;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\Actions\DonationTestDataTrait;
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

        $this->assertEquals(96_868, $donation->getAmountForCharityInPence());
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
        // Total fee        = £ 18.97
        // Fee on Gift Aid  = £  9.88
        // Amount after fee = £958.80

        $this->assertEquals(95_880, $donation->getAmountForCharityInPence());
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

        $this->assertEquals(95_585, $donation->getAmountForCharityInPence());
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

        $this->assertEquals(95_585, $donation->getAmountForCharityInPence());
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

        $this->assertEquals(97_264, $donation->getAmountForCharityInPence());
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
        $this->assertEquals(96_964, $donation->getAmountForCharityInPence());
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

        $this->assertEquals(97_264, $donation->getAmountForCharityInPence());
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
        $this->assertEquals(596, $donation->getAmountForCharityInPence());
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
            $settings,
        );
        $repo->setClient($donationClientProphecy->reveal());
        $repo->setLogger(new NullLogger());

        return $repo;
    }
}
