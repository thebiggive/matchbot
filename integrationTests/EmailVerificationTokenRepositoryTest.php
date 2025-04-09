<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\EmailVerificationTokenRepository;

class EmailVerificationTokenRepositoryTest extends IntegrationTest
{
    public function testYesWeHaveNoTokens(): void
    {
        $sut = $this->getService(EmailVerificationTokenRepository::class);

        $token = $sut->findRecentTokenForEmailAddress(
            EmailAddress::of('email@example.com'),
            new \DateTimeImmutable()
        );

        $this->assertNull($token);
    }
}
