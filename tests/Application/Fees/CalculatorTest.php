<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Fees;

use MatchBot\Application\Fees\Calculator;
use MatchBot\Tests\TestCase;

class CalculatorTest extends TestCase
{
    public function testStripeUKCardGBPDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'GB',
            '123',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 1.5% + 20p
        $this->assertEquals('2.05', $fees->coreFee);
        $this->assertEquals('0.41', $fees->feeVat);
    }

    public function testStripeUKCardGBPDonationWithFeeCover(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'GB',
            '123',
            'GbP', // Case doesn't matter for calculator
            false,
            '5', // 5% fee inc. 20% VAT.
        );

        // £6.15 fee covered, inc. VAT
        $this->assertEquals('5.13', $fees->coreFee);
        $this->assertEquals('1.02', $fees->feeVat);
    }

    public function testStripeUKCardEURDonationWithFeeCover(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'GB',
            '123',
            'EUR',
            false,
            '5', // 5% fee inc. 20% VAT.
        );

        // £6.15 fee covered, inc. VAT
        $this->assertEquals('5.13', $fees->coreFee);
        $this->assertEquals('1.02', $fees->feeVat);
    }

    public function testStripeUSCardGBPDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'US',
            '123',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 3.2% + 20p
        $this->assertEquals('4.14', $fees->coreFee);
    }

    public function testStripeUSCardGBPDonationWithTbgClaimingGiftAid(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'US',
            '100',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            true,
        );

        // 3.2% + 20p + 0.75% (net)
        $this->assertEquals('4.15', $fees->coreFee);
    }

    public function testStripeUKCardSEKDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'GB',
            '123',
            'sek',
            false,
        );

        // 1.5% + 1.80 SEK
        $this->assertEquals('3.65', $fees->coreFee);
    }

    public function testStripeUSCardSEKDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'US',
            '123',
            'SEK', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 3.2% + 1.80 SEK
        $this->assertEquals('5.74', $fees->coreFee);
    }

    /**
     * As per SEK but ensuring no fee VAT.
     */
    public function testStripeUSCardUSDDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'US',
            '100',
            'USD',
            false,
            '5',
        );

        $this->assertEquals('5.00', $fees->coreFee);
        $this->assertEquals('0.00', $fees->feeVat);
    }

    public function testStripeUSCardUSDDonationWithFeeCover(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            'visa',
            'US',
            '100',
            'USD',
            false,
            '5',
        );

        // We now record this as a fee to the charity which will be invoiced, without VAT,
        // and the donor will be charged a higher amount. E.g. here the core donation is
        // $100 and so that amount is passed to `Calculator`, but the donor card charge
        // would be $105.
        $this->assertEquals('5.00', $fees->coreFee);
        $this->assertEquals('0.00', $fees->feeVat);
    }

    /**
     * Worked example as given at https://biggive.org/our-fees/
     */
    public function testGBP10WVithoutGiftAidProvides695(): void
    {
        $donationAmount = '10.00';

        $fees = Calculator::calculate(
            psp: 'stripe',
            cardBrand: 'mastercard',
            cardCountry: 'GB',
            amount: $donationAmount,
            currencyCode: 'GBP',
            hasGiftAid: false
        );

        $this->assertSame('0.35', $fees->coreFee);
        $this->assertSame(9.65, $donationAmount - $fees->coreFee);
    }

    /**
     * Worked example as given at https://biggive.org/our-fees/
     */
    public function testGBP10WVithGiftAidProvides1208(): void
    {
        $donationAmount = '10.00';
        $giftAidAmount = $donationAmount / 4;

        $fees = Calculator::calculate(
            psp: 'stripe',
            cardBrand: 'mastercard',
            cardCountry: 'GB',
            amount: $donationAmount,
            currencyCode: 'GBP',
            hasGiftAid: true,
        );

        $this->assertSame('0.43', $fees->coreFee);
        $this->assertSame(12.07, $donationAmount - $fees->coreFee + $giftAidAmount);
    }

    /**
     * Worked example as given at https://biggive.org/our-fees/
     */
    public function testGBP10NonEUUKWVithoutGiftAidProvides695(): void
    {
        $donationAmount = '10.00';

        $fees = Calculator::calculate(
            psp: 'stripe',
            cardBrand: 'mastercard',
            cardCountry: 'US',
            amount: $donationAmount,
            currencyCode: 'GBP',
            hasGiftAid: false
        );

        $this->assertSame('0.52', $fees->coreFee);
        $this->assertSame(9.48, $donationAmount - $fees->coreFee);
    }

    public function testItRejectsUnexpectedCardBrand(): void
    {
        $this->expectExceptionMessage(
            'Unexpected card brand, expected brands are amex, diners, discover, eftpos_au, jcb, mastercard, ' .
            'unionpay, visa, unknown'
        );

        Calculator::calculate(
            'stripe',
            'Card brand that doesnt exist',
            'GB',
            '1',
            'GBP',
            false,
        );
    }
}
