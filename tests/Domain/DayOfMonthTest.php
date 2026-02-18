<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\DayOfMonth;
use PHPUnit\Framework\TestCase;

class DayOfMonthTest extends TestCase
{
    /**
     * @dataProvider dateTimeToDayOfMonth
     */
    public function testforMandateStartingAt(string $dateString, int $expected): void
    {
        $dayOfMonth = DayOfMonth::forMandateStartingAt(new \DateTimeImmutable($dateString));

        self::assertSame($expected, $dayOfMonth->value);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public function dateTimeToDayOfMonth(): array
    {
        return [
            'day 1 on January 1' => [
                '2020-01-01T00:00:00z', 1
            ],
            'day 1 on January 2nd in Kiritimati' => [
                // even if we somehow are using a datetime in a timezone far removed from the UK we should always
                // do calcuations in UK time, so this date should be treated as the 1st.
                '2020-01-02T12:00:00+14', 1
            ],
            'day 28 on January 28th' => [
                '2020-01-28T00:00:00z', 28
            ],
            'day 28 on January 29' => [
                '2020-01-29T00:00:00z', 28
            ],
        ];
    }
}
