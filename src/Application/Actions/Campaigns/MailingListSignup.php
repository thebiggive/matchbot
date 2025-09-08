<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Campaigns;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\MailingList;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * Handle mailing list signup requests
 */
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
        $parsedBody = $request->getParsedBody();
        
        if (!is_array($parsedBody)) {
            throw new HttpBadRequestException($request, 'Request body must be an array');
        }
        
        // Validate and extract required fields
        try {
            if (!isset($parsedBody['mailinglist'])) {
                throw new \InvalidArgumentException('Mailing list type is required');
            }
            
            if (!isset($parsedBody['firstName'])) {
                throw new \InvalidArgumentException('First name is required');
            }
            
            if (!isset($parsedBody['lastName'])) {
                throw new \InvalidArgumentException('Last name is required');
            }
            
            if (!isset($parsedBody['emailAddress'])) {
                throw new \InvalidArgumentException('Email address is required');
            }
            
            $mailingList = $parsedBody['mailinglist'];
            if (!is_string($mailingList)) {
                throw new \InvalidArgumentException('Mailing list type must be a string');
            }
            
            if ($mailingList !== 'donor' && $mailingList !== 'charity') {
                throw new \InvalidArgumentException('Mailing list must be either "donor" or "charity"');
            }
            
            $firstName = $parsedBody['firstName'];
            if (!is_string($firstName)) {
                throw new \InvalidArgumentException('First name must be a string');
            }
            
            $lastName = $parsedBody['lastName'];
            if (!is_string($lastName)) {
                throw new \InvalidArgumentException('Last name must be a string');
            }
            
            $emailAddress = $parsedBody['emailAddress'];
            if (!is_string($emailAddress)) {
                throw new \InvalidArgumentException('Email address must be a string');
            }
            
            if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email address format');
            }
            
            // Job title is required for charity mailing list
            $jobTitle = null;
            if ($mailingList === 'charity') {
                if (!isset($parsedBody['jobTitle'])) {
                    throw new \InvalidArgumentException('Job title is required for charity mailing list');
                }
                
                /** @var mixed $rawJobTitle */
                $rawJobTitle = $parsedBody['jobTitle'];
                if (!is_string($rawJobTitle) || empty($rawJobTitle)) {
                    throw new \InvalidArgumentException('Job title must be a non-empty string for charity mailing list');
                }
                $jobTitle = $rawJobTitle;
            } elseif (isset($parsedBody['jobTitle'])) {
                /** @var mixed $rawJobTitle */
                $rawJobTitle = $parsedBody['jobTitle'];
                if (!is_string($rawJobTitle)) {
                    throw new \InvalidArgumentException('Job title must be a string');
                }
                $jobTitle = $rawJobTitle;
            }
            
            // Optional organisation name
            $organisationName = null;
            if (isset($parsedBody['organisationName'])) {
                /** @var mixed $rawOrgName */
                $rawOrgName = $parsedBody['organisationName'];
                if (!is_string($rawOrgName)) {
                    throw new \InvalidArgumentException('Organisation name must be a string');
                }
                $organisationName = $rawOrgName;
            }
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage());
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
