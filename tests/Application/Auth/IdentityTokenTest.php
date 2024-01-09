<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use MatchBot\Application\Auth\IdentityToken;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use Psr\Log\NullLogger;

/**
 * Success currently tested as part of full Action tests in {@see CreateTest}.
 */
class IdentityTokenTest extends TestCase
{
    public function testCheckFailsWhenWrongDonationId(): void
    {
        $tokenHelper = new IdentityToken('https://unit-test-fake-id-sub.thebiggivetest.org.uk');

        $this->assertFalse(
            $tokenHelper->check(
                'someOtherPersonId',
                TestData\Identity::getTestIdentityTokenIncomplete(),
                new NullLogger(),
            ),
        );
    }

    public function testCheckFailsWhenSiteIsWrong(): void
    {
        $tokenHelper = new IdentityToken('https://another.example.org');

        $this->assertFalse(
            $tokenHelper->check(
                '12345678-1234-1234-1234-1234567890ab',
                TestData\Identity::getTestIdentityTokenIncomplete(),
                new NullLogger(),
            ),
        );
    }

    public function testCheckFailsAndPersonIdNullWhenSignatureGarbled(): void
    {
        $tokenHelper = new IdentityToken('https://unit-test-fake-id-sub.thebiggivetest.org.uk');
        $badToken = TestData\Identity::getTestIdentityTokenIncomplete() . 'x';

        $this->assertFalse(
            $tokenHelper->check(
                '12345678-1234-1234-1234-1234567890ab',
                $badToken,
                new NullLogger(),
            ),
        );

        $this->assertNull(IdentityToken::getPspId($badToken));
    }
}
