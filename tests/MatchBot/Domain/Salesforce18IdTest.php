<?php

namespace MatchBot\Domain;

use PHPUnit\Framework\TestCase;

class Salesforce18IdTest extends TestCase
{
    public function testItFixesCasing(): void
    {
        $idStringWithWrongCase = 'a05ws000004nmwxya2';

        $this->assertSame(
            'a05WS000004nMWXYA2',
            Salesforce18Id::ofCampaign($idStringWithWrongCase)->value
        );
    }
}
