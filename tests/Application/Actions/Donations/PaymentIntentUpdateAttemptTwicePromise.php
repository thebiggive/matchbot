<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Prophecy\Promise\PromiseInterface;
use Prophecy\Promise\ThrowPromise;
use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophecy\ObjectProphecy;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;

/**
 * Either fail with a lock error then succeed, or fail twice.
 */
class PaymentIntentUpdateAttemptTwicePromise implements PromiseInterface
{
    private int $callsCount = 0;

    public function __construct(
        private bool $succeedSecondTry,
        private bool $throwAlreadyCapturedSecondTry,
    ) {
    }

    #[\Override]
    public function execute(array $args, ObjectProphecy $object, MethodProphecy $method): void
    {
        if ($this->callsCount === 0 || (!$this->succeedSecondTry && !$this->throwAlreadyCapturedSecondTry)) {
            $this->callsCount++;

            $throwPromise = new ThrowPromise($this->getStripeObjectLockException());
            $throwPromise->execute($args, $object, $method);
        }

        if ($this->callsCount === 1 && $this->throwAlreadyCapturedSecondTry) {
            $this->callsCount++;

            $throwPromise = new ThrowPromise($this->getStripeAlreadyCapturedException());
            $throwPromise->execute($args, $object, $method);
        }
    }

    private function getStripeObjectLockException(): RateLimitException
    {
        $stripeErrorMessage = 'This object cannot be accessed right now because another ' .
            'API request or Stripe process is currently accessing it. If you see this error ' .
            'intermittently, retry the request. If you see this error frequently and are ' .
            'making multiple concurrent requests to a single object, make your requests ' .
            'serially or at a lower rate.';
        $exception = new RateLimitException($stripeErrorMessage);
        $exception->setStripeCode('lock_timeout');

        return $exception;
    }

    private function getStripeAlreadyCapturedException(): InvalidRequestException
    {
        $stripeErrorMessage = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
            'after a capture has already been made.';

        return new InvalidRequestException($stripeErrorMessage);
    }
}
