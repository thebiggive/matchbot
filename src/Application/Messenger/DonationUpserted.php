<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Message to tell workers to push a change to Salesforce.
 */
class DonationUpserted implements MessageGroupAwareInterface
{
    public const string SNAPSHOT_TAKEN_AT = 'snapshot_taken_at';

    /**
     * @param array<mixed>|null $jsonSnapshot
     */
    protected function __construct(
        public string $uuid,
        public array|null $jsonSnapshot
    ) {
        Assertion::uuid($this->uuid);
    }

    public static function fromDonation(Donation $donation): self
    {
        $jsonSnapshot = $donation->toSFApiModel();

        if ($jsonSnapshot !== null) {
            $jsonSnapshot[self::SNAPSHOT_TAKEN_AT] = (new \DateTimeImmutable())->format('c');
        }

        return new self(
            uuid: $donation->getUuid()->toString(),
            jsonSnapshot: $jsonSnapshot,
        );
    }

    /**
     * Returns an Envelope {@see Envelope} holding a message with a snapshot of the donation in its current state,
     * with a delay stamp if required to avoid reversing the status change direction in Salesforce.
     *
     * @param Donation $donation
     * @return Envelope
     */
    public static function fromDonationEnveloped(Donation $donation): Envelope
    {
        // 3s delay for successful donations to reduce risk of Donation\Update trying to reverse status change.
        $stamps = $donation->getDonationStatus()->isSuccessful() ? [new DelayStamp(3_000)] : [];

        return new Envelope(
            DonationUpserted::fromDonation($donation),
            $stamps,
        );
    }

    #[\Override]
    public function getMessageGroupId(): ?string
    {
        return 'donation.upserted.' . $this->uuid;
    }
}
