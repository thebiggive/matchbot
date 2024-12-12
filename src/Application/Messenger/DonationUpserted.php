<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\DomainException\MissingTransactionId;
use MatchBot\Domain\Donation;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

class DonationUpserted extends AbstractStateChanged implements MessageGroupAwareInterface
{
    public function __construct(public string $uuid, public array $jsonSnapshot)
    {
        parent::__construct($uuid, $jsonSnapshot);
    }

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
