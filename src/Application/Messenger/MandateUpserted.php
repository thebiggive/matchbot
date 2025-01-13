<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Donation;
use MatchBot\Domain\RegularGivingMandate;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

class MandateUpserted extends AbstractStateChanged implements MessageGroupAwareInterface
{
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
