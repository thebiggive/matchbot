<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Donation;

class DonationUpserted extends AbstractStateChanged
{
    private function __construct(public string $uuid, public array $json)
    {
        parent::__construct($uuid, $json);
    }

    public static function fromDonation(Donation $donation): self
    {
        return new self(
            uuid: $donation->getUuid(),
            json: $donation->toSFApiModel(),
        );
    }
}
