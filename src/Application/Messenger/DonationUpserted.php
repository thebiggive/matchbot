<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\DomainException\MissingTransactionId;
use MatchBot\Domain\Donation;

class DonationUpserted extends AbstractStateChanged
{
    private function __construct(public string $uuid, public array $jsonSnapshot)
    {
        parent::__construct($uuid, $jsonSnapshot);
    }

    /**
     * @throws MissingTransactionId
     */
    public static function fromDonation(Donation $donation): self
    {
        return new self(
            uuid: $donation->getUuid(),
            jsonSnapshot: $donation->toSFApiModel(),
        );
    }
}
