<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Assertion;

/**
 * For now, this is named with the idea that `SalesforceWriteProxyRepository` may soon use it for both donations
 * and e.g. recurring donation mandates. If we decide against that we could rename it to `AbstractDonationChanged`.
 */
abstract class AbstractStateChanged
{
    /**
     * @psalm-suppress PossiblyUnusedProperty Plan is to use `$json` with mandates
     */
    public array $json;

    protected function __construct(public string $uuid, array $json)
    {
        Assertion::uuid($this->uuid);
        $this->json = $json;
    }
}
