<?php

namespace MatchBot\Application\Messenger\Transport;

use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * This needs to be distinct from the service we inject with `TransportInterface` generically
 * (this app's own transport) so we can set it up for publishing messages to ClaimBot's queue.
 * It remains abstract so that it can be configured for either SQS or Redis in dependencies.php.
 */
abstract class ClaimBotTransport implements TransportInterface
{
}
