<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Fees;

use DI\ContainerBuilder;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Tests\Application\VatTrait;
use MatchBot\Tests\TestCase;

class CalculatorTest extends TestCase
{
    public function testStripeUKCardGBPDonation(): void
    {
        $calculator = new Calculator(
            $this->settingsWithVAT(),
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
        $calculator = new Calculator(
            $this->settingsWithVAT(),
            'stripe',
            'visa',
            'GB',
            '123',
            'GbP', // Case doesn't matter for calculator
            false,
            5, // 5% fee inc. 20% VAT.
        );

        // £6.15 fee covered, inc. VAT
        $this->assertEquals('5.13', $calculator->getCoreFee());
        $this->assertEquals('1.02', $calculator->getFeeVat());
    }

    public function testStripeUKCardEURDonationWithFeeCover(): void
    {
        $calculator = new Calculator(
            $this->settingsWithVAT(),
            'stripe',
            'visa',
            'GB',
            '123',
            'EUR',
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
            $this->settingsWithoutVAT(),
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

    public function testStripeUSCardGBPDonationWithTbgClaimingGiftAid(): void
    {
        $calculator = new Calculator(
            $this->settingsWithoutVAT(),
            'stripe',
            'visa',
            'US',
            '100',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            true,
        );

        // 3.2% + 20p + 0.75% (net)
        $this->assertEquals('4.15', $calculator->getCoreFee());
    }

    public function testStripeUKCardSEKDonation(): void
    {
        $calculator = new Calculator(
            $this->settingsWithoutVAT(),
            'stripe',
            'visa',
            'GB',
            '123',
            'sek',
            false,
        );

        // 1.5% + 1.80 SEK
        $this->assertEquals('3.65', $calculator->getCoreFee());
    }

    public function testStripeUSCardSEKDonation(): void
    {
        $calculator = new Calculator(
            $this->settingsWithoutVAT(),
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
        $calculator = new Calculator(
            $this->settingsWithVAT(),
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
        $calculator = new Calculator(
            $this->settingsWithVAT(),
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

    private function settingsWithVAT(): array
    {
        putenv('VAT_PERCENTAGE_LIVE=20');
        putenv('VAT_LIVE_DATE=2020-01-01');

        $settings = $this->settingsWithoutVAT();

        putenv('VAT_PERCENTAGE_LIVE=');
        putenv('VAT_LIVE_DATE=');

        return $settings;
    }

    private function settingsWithoutVAT(): array
    {
        $builder = new ContainerBuilder();
        $settingsFunction = require __DIR__ . '/../../../app/settings.php';
        $settingsFunction($builder);

        $settings = $builder->build()->get('settings');
        \assert(is_array($settings));

        return $settings;
    }
}
