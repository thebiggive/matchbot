<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use MatchBot\Domain\RegularGivingMandate;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

class MandateUpserted implements MessageGroupAwareInterface
{
    protected function __construct(public string $uuid, public array $jsonSnapshot)
    {
        Assertion::uuid($this->uuid);
    }

    public static function fromMandate(RegularGivingMandate $mandate): self
    {
        return new self(
            uuid: $mandate->getUuid()->toString(),
            jsonSnapshot: $mandate->toSFApiModel(),
        );
    }

    public function getMessageGroupId(): ?string
    {
        return 'manadate.upserted.' . $this->uuid;
    }
}
