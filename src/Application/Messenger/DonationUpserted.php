<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Donation;

class DonationUpserted extends AbstractStateChanged
{
    private function __construct(public string $uuid, public array $jsonSnapshot)
    {
        parent::__construct($uuid, $jsonSnapshot);
    }

    public static function fromDonation(Donation $donation): self
    {
        return new self(
            uuid: $donation->getUuid(),
            jsonSnapshot: $donation->toSFApiModel(),
        );
    }
}
