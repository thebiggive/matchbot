<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

/**
 * Message to tell workers to push a change to Salesforce.
 */
class DonationUpserted implements MessageGroupAwareInterface
{
    protected function __construct(
        public string $uuid,
        public array $jsonSnapshot
    )
    {
        Assertion::uuid($this->uuid);
    }

    public static function fromDonation(Donation $donation): self
    {
        $jsonSnapshot = [
            ...$donation->toSFApiModel(),
            'snapshot_taken_at' => (new \DateTimeImmutable())->format('c')
        ];
        return new self(
            uuid: $donation->getUuid()->toString(),
            jsonSnapshot: $jsonSnapshot,
        );
    }

    public function getMessageGroupId(): ?string
    {
        return 'donation.upserted.' . $this->uuid;
    }
}
