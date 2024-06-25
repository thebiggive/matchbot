<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;

class DonationUpdated extends AbstractStateChanged
{
    private function __construct(
        public string $uuid,
        public ?string $salesforceId,
        public array $json,
    ) {
        Assertion::notNull($this->salesforceId);

        parent::__construct($uuid, $salesforceId, $json);
    }

    public static function fromDonation(Donation $donation): self
    {
        return new self(
            uuid: $donation->getUuid(),
            salesforceId: $donation->getSalesforceId(),
            json: $donation->toHookModel(),
        );
    }
}
