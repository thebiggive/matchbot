<?php

namespace MatchBot\Tests\Application;

use ArrayIterator;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Stripe\Collection;
use Stripe\StripeObject;

trait StripeFormattingTrait
{
    use ProphecyTrait;

    protected function buildAutoIterableCollection(string $json): Collection|ObjectProphecy
    {
        /** @var \stdClass $itemsArray */
        $itemsArray = json_decode($json, false);
        /** @var StripeObject[] $itemData */
        $itemData = $itemsArray->data;

        $iterableCollection = $this->prophesize(Collection::class);
        $iterableCollection->autoPagingIterator()->willReturn(new ArrayIterator($itemData));
        $iterableCollection->count()->willReturn(count($itemData));

        return $iterableCollection->reveal();
    }

    protected function buildCollectionFromSingleObjectFixture(string $json): Collection|ObjectProphecy
    {
        $collectionRaw = new \stdClass();
        $collectionRaw->data = [json_decode($json, false)];

        return $this->buildAutoIterableCollection(json_encode($collectionRaw));
    }

    protected function buildEmptyCollection(): Collection|ObjectProphecy
    {
        $collectionRaw = new \stdClass();
        $collectionRaw->data = [];

        return $this->buildAutoIterableCollection(json_encode($collectionRaw));
    }
}
