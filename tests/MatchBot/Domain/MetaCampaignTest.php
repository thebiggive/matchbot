<?php

namespace MatchBot\Domain;

use MatchBot\Tests\TestCase;

class MetaCampaignTest extends TestCase
{
    /**
     * dataProvider targetWithAndWithoutOverrideProvider
     * @todo move to MetaCampaignRepositoryTest in integration tests.
     */
//    public function testTargetDependsOnMatchFundsAndOverride(int $override, int $totalMatchFunds, int $expectedTarget): void
//    {
//        $metaCampaign = TestCase::someMetaCampaign(
//            isRegularGiving: false,
//            isEmergencyIMF: false,
//            imfCampaignTargetOverride: Money::fromPence($override, Currency::GBP),
//            matchFundsTotal: Money::fromPence($totalMatchFunds, Currency::GBP),
//        );
//
//        $target = $metaCampaign->target();
//
//        $this->assertEquals(Money::fromPence($expectedTarget, Currency::GBP), $target);
//    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public function targetWithAndWithoutOverrideProvider(): array
    {
        // all amounts in pence
        // override, total match funds, expected target
        return [
            'nothing will come of nothing' => [0_00, 0_00, 0_00],
            'target is double match funds' => [0_00, 5_00, 10_00],
            'overridden target' => [0_01, 5_00, 0_01],
        ];
    }

    /** @dataProvider shouldBeIndexedProvider */
    public function testMetaCampignsAreIndexOrNotAccordingToStartDate(
        string $nowString,
        string $startDateString,
        bool $expectedResult
    ): void {
        $now = new \DateTimeImmutable($nowString);
        $startDate = new \DateTimeImmutable($startDateString);
        $metaCampaign = self::someMetaCampaign(false, false, startDate: $startDate);

        $shouldBeIndexed = $metaCampaign->shouldBeIndexed($now);

        $this->assertSame($expectedResult, $shouldBeIndexed);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public function shouldBeIndexedProvider(): array
    {
        return [
            'campaign starts within 4 weeks of now (3 weeks after now, should be indexed)'      => ['2025-01-01T12:00:00z', '2025-01-22T12:00:00z', true],
            'campaign starts more than 4 weeks from now (5 weeks after now, not indexed)'       => ['2025-01-01T12:00:00z', '2025-02-05T12:00:00z', false],
            'campaign starts before INDEX_FROM 2019-12-01 (not indexed)'                        => ['2025-01-01T12:00:00z', '2019-11-30T23:59:59z', false],
            'campaign starts exactly at INDEX_FROM 2019-12-01 (> not >=, not indexed)'          => ['2025-01-01T12:00:00z', '2019-12-01T00:00:00z', false],
            'campaign starts exactly at 4 week boundary (< not <=, not indexed)'                => ['2025-01-01T12:00:00z', '2025-01-29T12:00:00z', false],
            'campaign starts just after INDEX_FROM 2019-12-01 (should be indexed)'              => ['2025-01-01T12:00:00z', '2019-12-01T00:00:01z', true],
            'campaign starts just before 4 week boundary (1 second before, should be indexed)'  => ['2025-01-01T12:00:00z', '2025-01-29T11:59:59z', true],
        ];
    }
}
