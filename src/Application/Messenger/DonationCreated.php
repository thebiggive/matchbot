<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Donation;

class DonationCreated extends AbstractStateChanged
{
    public static function fromDonation(Donation $donation): self
    {
        return new self(
            uuid: $donation->getUuid(),
            salesforceId: null,
            json: $donation->toApiModel(),
        );
    }
}
