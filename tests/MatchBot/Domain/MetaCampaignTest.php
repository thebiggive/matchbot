<?php

namespace MatchBot\Domain;

use MatchBot\Tests\TestCase;

class MetaCampaignTest extends TestCase
{
    /** @dataProvider targetWithAndWithoutOverrideProvider */
    public function testTargetDependsOnMatchFundsAndOverride(int $override, int $totalMatchFunds, int $expectedTarget): void
    {
        $metaCampaign = TestCase::someMetaCampaign(
            isRegularGiving: false,
            isEmergencyIMF: false,
            imfCampaignTargetOverride: Money::fromPence($override, Currency::GBP),
            matchFundsTotal: Money::fromPence($totalMatchFunds, Currency::GBP),
        );

        $target = $metaCampaign->target();

        $this->assertEquals(Money::fromPence($expectedTarget, Currency::GBP), $target);
    }

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
}
