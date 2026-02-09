<?php

namespace Domain;

use MatchBot\Application\LazyAssertionException;
use MatchBot\Domain\DonorName;
use MatchBot\Tests\TestCase;

class DonorNameTest extends TestCase
{
    /** @dataProvider allowedAndNotAllowedNames */
    public function testItAllowsSomeNamesAndNotOthers(string $firstName, string $lastName, bool $isAllowed): void
    {
        if (! $isAllowed) {
            $this->expectException(LazyAssertionException::class);
        }

        $name = DonorName::of($firstName, $lastName);

        if ($isAllowed) {
            $this->assertSame($firstName === '' ? $lastName : "$firstName $lastName", $name->fullName());
        }
    }

    /**
     * @return array<array{0: string, 1: string, 2: boolean}>
     */
    public function allowedAndNotAllowedNames(): array
    {
        return [
            'Typical name' => ['Joe', 'Bloggs', true],
            'Very long name' => [str_repeat('f', 255), str_repeat('l', 255), true],
            'Excessively long first name' => [str_repeat('f', 256), str_repeat('l', 255), false],
            'Excessively long last name' => [str_repeat('f', 255), str_repeat('l', 256), false],
            'Excessively short first name' => ['', 'Bloggs', true], // matches the pattern of an organisation donor
            'Excessively short last name' => ['Joe', '', false],
            'First name with long number (likeely entered by mistake)' => ['123 456', 'Bloggs', false],
            'Last name with long number (likeely entered by mistake)' => ['Joe', '123 456', false],
            'First Name with short number (maybe not a mistake)' => ['Joe the 12345th', 'Blogs ', true],
        ];
    }
}
