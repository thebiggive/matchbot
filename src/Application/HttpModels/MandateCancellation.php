<?php

namespace MatchBot\Application\HttpModels;

use Assert\AssertionFailedException;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Country;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Money;
use MatchBot\Domain\PostCode;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeConfirmationTokenId;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

readonly class MandateCancellation
{
    public UuidInterface $mandateUUID;
    public string $cancellationReason;

    /**
     * @psalm-suppress PossiblyUnusedMethod - called by Symfony Serializer
     *
     * @param string $mandateUUID Must be a valid UUID
     * @param string $cancellationReason Up to 500 bytes.
     * @throws AssertionFailedException
     */
    public function __construct(
        string $mandateUUID,
        string $cancellationReason = '',
    ) {
        $this->cancellationReason = trim($cancellationReason);

        Assertion::maxLength($this->cancellationReason, 500);

        Assertion::uuid($mandateUUID);
        $this->mandateUUID = Uuid::fromString($mandateUUID);
    }
}
