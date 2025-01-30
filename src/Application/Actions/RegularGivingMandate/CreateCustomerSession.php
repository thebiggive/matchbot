<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Security\Security;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Creates a stripe customer session for use with regular giving
 */
class CreateCustomerSession extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private Stripe $stripeClient,
        private CampaignRepository $campaignRepository,
        private Security $security,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $donor = $this->security->requireAuthenticatedDonorAccountWithPassword($request);

        $body = json_decode($request->getBody()->getContents(), associative: true);
        \assert(is_array($body));
        $campaignId = $body['campaignId'] ?? null;
        if ($campaignId !== null) {
            \assert(is_string($campaignId));
            $this->campaignRepository->pullFromSFIfNotPresent(Salesforce18Id::ofCampaign($campaignId));
        }

        $customerSession = $this->stripeClient->createRegularGivingCustomerSession($donor->stripeCustomerId);

        return new JsonResponse(['stripeSessionSecret' =>  $customerSession->client_secret]);
    }
}
