<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;

/**
 * For now, this is named with the idea that `SalesforceWriteProxyRepository` may soon use it for both donations
 * and e.g. recurring donation mandates. If we decide against that we could rename it to `AbstractDonationChanged`.
 */
abstract class AbstractStateChanged
{
    protected function __construct(
        public string $uuid,
        public ?string $salesforceId,
        public array $json,
    ) {
        Assertion::uuid($this->uuid);
    }
}
