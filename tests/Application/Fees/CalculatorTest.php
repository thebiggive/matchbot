<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Fees;

use MatchBot\Application\Fees\Calculator;
use MatchBot\Tests\TestCase;

class CalculatorTest extends TestCase
{
    public function testStripeUKCardGBPDonation(): void
    {
        $calculator = new Calculator(
            $this->getAppInstance()->getContainer()->get('settings'),
            'stripe',
            'visa',
            'GB',
            '123',
            'GBP', // Comes from Donation so input is uppercase although Stripe is lowercase internally.
            false,
        );

        // 1.5% + 20p
        $this->assertEquals('2.05', $calculator->getCoreFee());
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
}
