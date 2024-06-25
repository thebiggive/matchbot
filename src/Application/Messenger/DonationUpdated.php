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
        // TODO Things like Stripe hooks could come back before pushing to Salesforce is complete, so we need to be
        // able to queue jobs before it's known.
        // This probably implies that the consumer needs a conditional DB re-query too.
//        Assertion::notNull($this->salesforceId);

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
