<?php

namespace MatchBot\Application\Messenger\Transport;

use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * This needs to be distinct from the service we inject with `TransportInterface` generically
 * (this app's default) so we can set it up for publishing lower priority messages which upsert
 * Donations in Salesforce.
 *
 * It remains abstract so that it can be configured for either SQS or Redis in dependencies.php.
 */
abstract class SalesforcePushTransport implements TransportInterface
{
}
