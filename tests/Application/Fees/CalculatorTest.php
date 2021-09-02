<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Fees;

use MatchBot\Application\Fees\Calculator;
use MatchBot\Tests\Application\VatTrait;
use MatchBot\Tests\TestCase;

class CalculatorTest extends TestCase
{
    use VatTrait;

    public function testStripeUKCardGBPDonation(): void
    {
        $settingsWithVAT = $this->getUKLikeVATSettings(
            $this->getAppInstance()->getContainer()->get('settings')
        );

        $calculator = new Calculator(
            $settingsWithVAT,
            'stripe',
            'visa',
            'GB',
            '123',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 1.5% + 20p
        $this->assertEquals('2.05', $calculator->getCoreFee());
        $this->assertEquals('0.41', $calculator->getFeeVat());
    }

    public function testStripeUSCardGBPDonation(): void
    {
        $calculator = new Calculator(
            $this->getAppInstance()->getContainer()->get('settings'),
            'stripe',
            'visa',
            'US',
            '123',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 3.2% + 20p
        $this->assertEquals('4.14', $calculator->getCoreFee());
    }

    public function testStripeUKCardSEKDonation(): void
    {
        $calculator = new Calculator(
            $this->getAppInstance()->getContainer()->get('settings'),
            'stripe',
            'visa',
            'GB',
            '123',
            'SEK', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 1.5% + 1.80 SEK
        $this->assertEquals('3.65', $calculator->getCoreFee());
    }

    public function testStripeUSCardSEKDonation(): void
    {
        $calculator = new Calculator(
            $this->getAppInstance()->getContainer()->get('settings'),
            'stripe',
            'visa',
            'US',
            '123',
            'SEK', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 3.2% + 1.80 SEK
        $this->assertEquals('5.74', $calculator->getCoreFee());
    }

    /**
     * As per SEK but ensuring no fee VAT.
     */
    public function testStripeUSCardUSDDonation(): void
    {
        $settingsWithVAT = $this->getUKLikeVATSettings(
            $this->getAppInstance()->getContainer()->get('settings')
        );

        $calculator = new Calculator(
            $settingsWithVAT,
            'stripe',
            'visa',
            'US',
            '100',
            'USD',
            false,
            5,
        );

        $this->assertEquals('5.00', $calculator->getCoreFee());
        $this->assertEquals('0.00', $calculator->getFeeVat());
    }

    public function testStripeUSCardUSDDonationWithFeeCover(): void
    {
        $settingsWithVAT = $this->getUKLikeVATSettings(
            $this->getAppInstance()->getContainer()->get('settings')
        );

        $calculator = new Calculator(
            $settingsWithVAT,
            'stripe',
            'visa',
            'US',
            '100',
            'USD',
            false,
            5,
            true,
        );

        // No fee to charity as the donor has paid it to TBG instead.
        $this->assertEquals('0.00', $calculator->getCoreFee());
        $this->assertEquals('0.00', $calculator->getFeeVat());
    }
}
