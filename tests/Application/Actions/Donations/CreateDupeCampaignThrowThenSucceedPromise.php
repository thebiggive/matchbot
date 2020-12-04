<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Domain\Donation;
use Prophecy\Promise\PromiseInterface;
use Prophecy\Promise\ThrowPromise;
use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophecy\ObjectProphecy;

class CreateDupeCampaignThrowThenSucceedPromise implements PromiseInterface
{
    private int $callsCount = 0;
    private Donation $donationToReturn;

    public function __construct(Donation $donationToReturn)
    {
        $this->donationToReturn = $donationToReturn;
    }

    public function execute(array $args, ObjectProphecy $object, MethodProphecy $method)
    {
        if ($this->callsCount === 0) {
            $this->callsCount++;

            $throwPromise = new ThrowPromise(UniqueConstraintViolationException::class);
            $throwPromise->execute($args, $object, $method); // throw appropriately
        }

        return $this->donationToReturn;
    }
}
