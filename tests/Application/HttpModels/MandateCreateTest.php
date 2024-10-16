<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\HttpModels;

use MatchBot\Application\HttpModels\MandateCreate;
use MatchBot\Tests\TestCase;
use Symfony\Component\Serializer\SerializerInterface;

class MandateCreateTest extends TestCase
{
    public function testItDeserialisesFromJSON(): void
    {
        $serialiser = $this->getContainer()->get(SerializerInterface::class);
        $mandateCreate = $serialiser->deserialize(
            <<<'JSON'
            {
                "amountInPence":  100,
                "currency":  "GBP",
                "dayOfMonth": "1",
                "campaignId": "1CampaignId1234567",
                "giftAid": false
            }
            JSON,
            MandateCreate::class,
            'json'
        );

        $this->assertInstanceOf(MandateCreate::class, $mandateCreate);
    }
}
