<?php

namespace MatchBot\Domain;

use PHPUnit\Framework\TestCase;

class RyftAccountIdTest extends TestCase
{
    public function testRyftAccountIdCreation(): void
    {
        $ryftAccountId = RyftAccountId::of('ac_b83f2653-06d7-44a9-a548-5825e8186004');
        $this->assertSame('ac_b83f2653-06d7-44a9-a548-5825e8186004', $ryftAccountId->ryftAccountId);
    }

    public function testItRejectsMalformedID(): void
    {
        $this->expectExceptionMessage('Given ryft account ID ac_b83f2653-06d7-44a9-a548-5825e81860044 does not match expected pattern');
        RyftAccountId::of('ac_b83f2653-06d7-44a9-a548-5825e81860044');
    }
}
