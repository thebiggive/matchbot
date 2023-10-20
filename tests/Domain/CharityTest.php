<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Charity;
use MatchBot\Tests\TestCase;

class CharityTest extends TestCase
{
    public function testInvalidRegularIsDenied(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Regulator N/A not known');

        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setRegulator('N/A');
    }

    public function testBlankRegularIsDenied(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Regulator  not known');

        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setRegulator('');
    }

    public function testNullRegulatorIsAllowed(): void
    {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setRegulator(null);

        $this->assertNull($charity->getRegulator());
    }
}
