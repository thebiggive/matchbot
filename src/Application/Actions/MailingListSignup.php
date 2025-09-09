<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Assertion;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\MailingList;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;

class MailingListSignup extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private MailingList $mailingListClient,
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        $body = (string) $request->getBody();

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->info("Mailing list signup non-serialisable payload was: $body");
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON in request body',
            ], 400);
        }

        // Validate request body is an array
        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Request body must be a JSON object',
            ], 400);
        }

        // Validate required fields
        if (!isset($data['mailinglist'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Mailing list type is required',
            ], 400);
        }

        if (!isset($data['firstName'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'First name is required',
            ], 400);
        }

        if (!isset($data['lastName'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Last name is required',
            ], 400);
        }

        if (!isset($data['emailAddress'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email address is required',
            ], 400);
        }

        $mailingList = $data['mailinglist'];
        if (!is_string($mailingList)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Mailing list type must be a string',
            ], 400);
        }

        if ($mailingList !== 'donor' && $mailingList !== 'charity') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Mailing list must be either "donor" or "charity"',
            ], 400);
        }

        $firstName = $data['firstName'];
        if (!is_string($firstName)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'First name must be a string',
            ], 400);
        }

        $lastName = $data['lastName'];
        if (!is_string($lastName)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Last name must be a string',
            ], 400);
        }

        $emailAddress = $data['emailAddress'];
        if (!is_string($emailAddress)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email address must be a string',
            ], 400);
        }

        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid email address format',
            ], 400);
        }

        // Job title is required for charity mailing list
        $jobTitle = null;
        if ($mailingList === 'charity') {
            if (!isset($data['jobTitle'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Job title is required for charity mailing list',
                ], 400);
            }

            /** @var mixed $rawJobTitle */
            $rawJobTitle = $data['jobTitle'];
            if (!is_string($rawJobTitle) || empty($rawJobTitle)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Job title must be a non-empty string for charity mailing list',
                ], 400);
            }
            $jobTitle = $rawJobTitle;
        } elseif (isset($data['jobTitle'])) {
            /** @var mixed $rawJobTitle */
            $rawJobTitle = $data['jobTitle'];
            if (!is_string($rawJobTitle)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Job title must be a string',
                ], 400);
            }
            $jobTitle = $rawJobTitle;
        }

        // Optional organisation name
        $organisationName = null;
        if (isset($data['organisationName'])) {
            /** @var mixed $rawOrgName */
            $rawOrgName = $data['organisationName'];
            if (!is_string($rawOrgName)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Organisation name must be a string',
                ], 400);
            }
            $organisationName = $rawOrgName;
        }

        try {
            $success = $this->mailingListClient->signup(
                $mailingList,
                $firstName,
                $lastName,
                $emailAddress,
                $jobTitle,
                $organisationName
            );

            if (!$success) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Failed to sign up to mailing list',
                ], 500);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Successfully signed up to mailing list',
            ]);
        } catch (BadRequestException $e) {
            $this->logger->error('Mailing list signup failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to sign up to mailing list',
            ], 400);
        } catch (\Exception $e) {
            $this->logger->error('Mailing list signup error: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'An error occurred while processing your request',
            ], 500);
        }
    }
}
