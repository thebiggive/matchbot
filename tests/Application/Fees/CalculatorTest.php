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

    public function testStripeUKCardGBPDonationWithFeeCover(): void
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
            5, // 5% fee inc. 20% VAT.
        );

        // £6.15 fee covered, inc. VAT
        $this->assertEquals('5.13', $calculator->getCoreFee());
        $this->assertEquals('1.02', $calculator->getFeeVat());
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
        );

        // We now record this as a fee to the charity which will be invoiced, without VAT,
        // and the donor will be charged a higher amount. E.g. here the core donation is
        // $100 and so that amount is passed to `Calculator`, but the donor card charge
        // would be $105.
        $this->assertEquals('5.00', $calculator->getCoreFee());
        $this->assertEquals('0.00', $calculator->getFeeVat());
    }
}
