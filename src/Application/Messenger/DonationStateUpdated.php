<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;

readonly class DonationStateUpdated
{
    private function __construct(
        public string $donationUUID,
        public bool $donationIsNew
    ) {
        Assertion::uuid($this->donationUUID);
    }

    public static function fromDonation(Donation $donation, bool $isNew = false): self
    {
        return new self($donation->getUuid(), $isNew);
    }
}
