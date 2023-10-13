<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\DonorAccount;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Domain\DonorAccountRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * Creates a record that a donor has (or intends to have) an account to transfer funds to in advance of donating to
 * charity. We will need this to email them a confirmation when the funds are recieved.
 */
class Create extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private DonorAccountRepository $donorAccountRepository
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        try {
            $json = $request->getBody()->getContents();

            $requestBody = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(is_array($requestBody));

        $stripeCustomerId = $request->getAttribute(PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME);

        $emailAddress = $requestBody['emailAddress'];
        \assert(is_string($emailAddress) && is_string($stripeCustomerId));

        $donorAccount = new \MatchBot\Domain\DonorAccount($emailAddress, $stripeCustomerId);

        $this->donorAccountRepository->save($donorAccount);

        return new \Slim\Psr7\Response(201);
    }
}
