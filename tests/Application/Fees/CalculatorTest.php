<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Fees;

use MatchBot\Application\Fees\Calculator;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Tests\TestCase;

class CalculatorTest extends TestCase
{
    public function testStripeUKCardGBPDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            CardBrand::from('visa'),
            Country::fromAlpha2('GB'),
            '123',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 1.5% + 20p
        $this->assertEquals('2.05', $fees->coreFee);
        $this->assertEquals('0.41', $fees->feeVat);
    }

    public function testStripeUSCardGBPDonation(): void
    {
        $fees = Calculator::calculate(
            'stripe',
            CardBrand::from('visa'),
            Country::fromAlpha2('US'),
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
            CardBrand::from('visa'),
            Country::fromAlpha2('US'),
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
            CardBrand::from('visa'),
            Country::fromAlpha2('GB'),
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
            CardBrand::from('visa'),
            Country::fromAlpha2('US'),
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
            CardBrand::from('visa'),
            Country::fromAlpha2('US'),
            '100',
            'USD',
            false,
        );

        $this->assertEquals('3.50', $fees->coreFee);
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
            cardBrand: CardBrand::from('mastercard'),
            cardCountry: Country::fromAlpha2('GB'),
            amount: $donationAmount,
            currencyCode: 'GBP',
            hasGiftAid: false
        );

        $this->assertSame('0.35', $fees->coreFee);

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertSame(9.65, (float)$donationAmount - (float)$fees->coreFee);
    }

    /**
     * Worked example as given at https://biggive.org/our-fees/
     */
    public function testGBP10WVithGiftAidProvides1208(): void
    {
        $donationAmount = '10.00';
        $giftAidAmount = (int)$donationAmount / 4;

        $fees = Calculator::calculate(
            psp: 'stripe',
            cardBrand: CardBrand::from('mastercard'),
            cardCountry: Country::fromAlpha2('GB'),
            amount: $donationAmount,
            currencyCode: 'GBP',
            hasGiftAid: true,
        );

        $this->assertSame('0.43', $fees->coreFee);

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertSame(12.07, (float)$donationAmount - (float)$fees->coreFee + $giftAidAmount);
    }

    /**
     * Worked example as given at https://biggive.org/our-fees/
     */
    public function testGBP10NonEUUKWVithoutGiftAidProvides695(): void
    {
        $donationAmount = '10.00';

        $fees = Calculator::calculate(
            psp: 'stripe',
            cardBrand: CardBrand::from('mastercard'),
            cardCountry: Country::fromAlpha2('US'),
            amount: $donationAmount,
            currencyCode: 'GBP',
            hasGiftAid: false
        );

        $this->assertSame('0.52', $fees->coreFee);

        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertSame(9.48, (float)$donationAmount - (float)$fees->coreFee);
    }
}
