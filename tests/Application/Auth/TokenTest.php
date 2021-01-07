<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use MatchBot\Application\Auth\Token;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;

class TokenTest extends TestCase
{
    public function testCreateReturnsValidLookingToken(): void
    {
        $token = Token::create('someDonationId');

        $this->assertMatchesRegularExpression('/^[^.]+\.[^.]+\.[^.]+$/', $token);
    }

    public function testCheckPassesWhenAllValid(): void
    {
        $token = Token::create('someDonationId');

        $this->assertTrue(Token::check('someDonationId', $token, new NullLogger()));
    }

    public function testCheckFailsWhenWrongDonationId(): void
    {
        $token = Token::create('someDonationId');

        $this->assertFalse(Token::check('someOtherDonationId', $token, new NullLogger()));
    }

    public function testCheckFailsWhenSignatureGarbled(): void
    {
        $token = Token::create('someDonationId');

        $this->assertFalse(Token::check('someDonationId', $token . 'X', new NullLogger()));
    }
}
