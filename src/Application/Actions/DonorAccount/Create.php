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

        $stripeCustomerIdString = $request->getAttribute(PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($stripeCustomerIdString));

        $emailAddressString = $requestBody['emailAddress'] ??
            throw new HttpBadRequestException($request, 'Expected emailAddress');
        \assert(is_string($emailAddressString));

        /** @var array{firstName: string, lastName: string} $donorNameArray */
        $donorNameArray = $requestBody['donorName'] ??
            throw new HttpBadRequestException($request, 'Expected donorName');

        try {
            $emailAddress = EmailAddress::of($emailAddressString);
            $donorName = DonorName::of($donorNameArray['firstName'], $donorNameArray['lastName']);
            $stripeCustomerId = StripeCustomerId::of($stripeCustomerIdString);
        } catch (AssertionFailedException | LazyAssertionException $e) {
            return $this->validationError(
                $response,
                $e->getMessage(),
            );
        }

        $donorAccount = new DonorAccount(
            $emailAddress,
            $donorName,
            $stripeCustomerId,
        );

        try {
            $this->donorAccountRepository->save($donorAccount);
        } catch (UniqueConstraintViolationException $e) {
            return $this->validationError(
                $response,
                "Donor Account already exists for stripe account " . $donorAccount->stripeCustomerId->stripeCustomerId,
            );
        }

        return new \Slim\Psr7\Response(201);
    }
}
