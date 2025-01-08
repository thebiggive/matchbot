<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Fund;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

readonly class FundTotalUpdated implements MessageGroupAwareInterface
{
    public array $jsonSnapshot;

    protected function __construct(public string $salesforceId, array $json)
    {
        $this->jsonSnapshot = $json;
    }

    public static function fromFund(Fund $fund): self
    {
        $sfId = $fund->getSalesforceId();
        Assertion::notNull($sfId); // Only updates to existing Funds are supported.

        return new self(
            salesforceId: $sfId,
            json: $fund->toAmountUsedUpdateModel(),
        );
    }

    public function getMessageGroupId(): ?string
    {
        return 'fund.total.updated.' . $this->salesforceId;
    }
}
