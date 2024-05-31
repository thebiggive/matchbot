<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use Ramsey\Uuid\Uuid;

readonly class DonationStateUpdated
{
    public function __construct(
        public string $donationUUID,
        public bool $isNew
    ) {
        Assertion::uuid($this->donationUUID);
    }

    public static function fromDonation(Donation $donation, bool $isNew = false): self
    {
        return new self($donation->getUuid(), $isNew);
    }
}
