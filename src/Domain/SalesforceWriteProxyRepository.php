<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Client;

/**
 * @template T of SalesforceWriteProxy
 * @template C of Client\Common
 * @template-extends SalesforceProxyRepository<T, C>
 */
abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    abstract public function doCreate(AbstractStateChanged $changeMessage): void;

    abstract public function doUpdate(AbstractStateChanged $changeMessage): void;

    /**
     * @psalm-suppress PossiblyUnusedMethod - used via DonationRepository interface
     */
    public function push(AbstractStateChanged $changeMessage, bool $isNew): void
    {
        if ($isNew) {
            $this->doCreate($changeMessage);

            return;
        }

        $this->doUpdate($changeMessage);
    }
}
