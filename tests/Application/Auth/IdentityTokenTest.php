<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use MatchBot\Application\Auth\IdentityTokenService;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use Psr\Log\NullLogger;

/**
 * Success currently tested as part of full Action tests in {@see CreateTest}.
 */
class IdentityTokenTest extends TestCase
{
    public const string PERSON_UUID = '12345678-1234-1234-1234-1234567890ab';

    public function testCheckFailsWhenWrongDonationId(): void
    {
        $tokenHelper = new IdentityTokenService('https://unit-test-fake-id-sub.thebiggivetest.org.uk', ['secret']);

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
        $tokenHelper = new IdentityTokenService('https://another.example.org', ['secret']);

        $this->assertFalse(
            $tokenHelper->check(
                self::PERSON_UUID,
                TestData\Identity::getTestIdentityTokenIncomplete(),
                new NullLogger(),
            ),
        );
    }

    public function testCheckFailsAndPersonIdNullWhenSignatureGarbled(): void
    {
        $tokenHelper = new IdentityTokenService('https://unit-test-fake-id-sub.thebiggivetest.org.uk', ['secret']);
        $badToken = TestData\Identity::getTestIdentityTokenIncomplete() . 'x';

        $this->assertFalse(
            $tokenHelper->check(
                self::PERSON_UUID,
                $badToken,
                new NullLogger(),
            ),
        );

        $this->assertNull($tokenHelper->getPspId($badToken));
    }
}
