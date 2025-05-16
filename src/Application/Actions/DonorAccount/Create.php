<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\DonorAccount;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\LazyAssertionException;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\StripeCustomerId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * Creates a record that a donor has (or intends to have) an account to transfer funds by bank transfer
 * in advance of donating to charity, or to make Regular Giving arrangements. We need this to
 * email them a confirmation when the funds are received or to manage capturing off-session donations.
 *
 * @deprecated We should stop using this once syncing via the queue is stable in Production.
 */
class Create extends Action
{
    public function __construct(
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        // no-op: we rely on getting the donor account synced from Identity server instead.
        // @todo - stop calling this from Frontend and then delete.
        return new \Slim\Psr7\Response(201);
    }
}
