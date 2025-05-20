<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Charity;
use MatchBot\Domain\Salesforce18Id;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageDeduplicationAwareInterface;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

/**
 * Message dispatched when Salesforce tells us that something material to donation processing or
 * Gift Aid changed about the {@see Charity}.
 */
readonly class CharityUpdated implements MessageDeduplicationAwareInterface, MessageGroupAwareInterface
{
    /**
     * @param Salesforce18Id<Charity> $charityAccountId
     */
    public function __construct(public Salesforce18Id $charityAccountId, private ?string $requestTraceId)
    {
    }

    /**
     * As MatchBot uses a FIFO SQS queue in non-dev environments, we must provide a deduplication
     * ID to allow for multiple updates within a 5 minute period.
     *
     * @link https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/FIFO-queues-exactly-once-processing.html
     */
    #[\Override]
    public function getMessageDeduplicationId(): ?string
    {
        return $this->requestTraceId;
    }

    #[\Override]
    public function getMessageGroupId(): ?string
    {
        return 'charity.updated.' . $this->charityAccountId->value;
    }
}
