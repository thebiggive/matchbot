<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Donation;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

/**
 * Message to tell workers to push a change to Salesforce.
 */
class DonationUpserted extends AbstractStateChanged implements MessageGroupAwareInterface
{
    public static function fromDonation(Donation $donation): self
    {
        return new self(
            uuid: $donation->getUuid()->toString(),
            jsonSnapshot: $donation->toSFApiModel(),
        );
    }

    public function getMessageGroupId(): ?string
    {
        return 'donation.upserted.' . $this->uuid;
    }
}
