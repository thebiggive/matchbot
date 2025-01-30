<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\RegularGivingMandate;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

class MandateUpserted implements MessageGroupAwareInterface
{
    protected function __construct(public string $uuid, public array $jsonSnapshot)
    {
        Assertion::uuid($this->uuid);
    }

    /**
     * @param RegularGivingMandate $mandate
     * @param DonorAccount $donor - must have a UUID matching that indicated within the RegularGivingMandate. We include
     * information about the donor in the mandate model since the donor accounts are not sent independently.
     */
    public static function fromMandate(RegularGivingMandate $mandate, DonorAccount $donor): self
    {
        return new self(
            uuid: $mandate->getUuid()->toString(),
            jsonSnapshot: $mandate->toSFApiModel($donor),
        );
    }

    public function getMessageGroupId(): ?string
    {
        return 'manadate.upserted.' . $this->uuid;
    }
}
