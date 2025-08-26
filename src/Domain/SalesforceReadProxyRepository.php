<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\Assertion;
use MatchBot\Client;

/**
 * @template T of SalesforceReadProxy
 * @template C of Client\Common
 * @template-extends SalesforceProxyRepository<T, C>
 */
abstract class SalesforceReadProxyRepository extends SalesforceProxyRepository
{
}
