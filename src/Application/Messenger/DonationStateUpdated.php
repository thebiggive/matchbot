<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use Ramsey\Uuid\Uuid;

readonly class DonationStateUpdated
{
    public function __construct(
        public string $donationUUID,
    ) {
        Assertion::uuid($this->donationUUID);
    }

    public static function fromDonation(Donation $donation): self
    {
        return new self($donation->getUuid());
    }
}
