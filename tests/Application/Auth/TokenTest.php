<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use MatchBot\Application\Auth\DonationToken;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;

class TokenTest extends TestCase
{
    public function testCreateReturnsValidLookingToken(): void
    {
        $token = DonationToken::create('someDonationId');

        $this->assertMatchesRegularExpression('/^[^.]+\.[^.]+\.[^.]+$/', $token);
    }

    public function testCheckPassesWhenAllValid(): void
    {
        $token = DonationToken::create('someDonationId');

        $this->assertTrue(DonationToken::check('someDonationId', $token, new NullLogger()));
    }

    public function testCheckFailsWhenWrongDonationId(): void
    {
        $token = DonationToken::create('someDonationId');

        $this->assertFalse(DonationToken::check('someOtherDonationId', $token, new NullLogger()));
    }

    public function testCheckFailsWhenSignatureGarbled(): void
    {
        $token = DonationToken::create('someDonationId');

        $this->assertFalse(DonationToken::check('someDonationId', $token . 'X', new NullLogger()));
    }
}
