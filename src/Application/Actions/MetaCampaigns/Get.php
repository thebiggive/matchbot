<?php

namespace MatchBot\Application\Actions\MetaCampaigns;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class Get extends Action {

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        return new JsonResponse([
            'metaCampaign' => [
                'id' => '',
                'title' => 'placeholder metacampaign',
                'currencyCode' => '',
                'status' => '',
                'hidden' => '',
                'ready' => '',
                'summary' => 'Just testing for now',
                'bannerUri' => '',
                'amountRaised' => 0,
                'matchFundsRemaining' => 0,
                'donationCount' => 0,
                'startDate' => '',
                'endDate' => '',
                'matchFundsTotal' => 0,
                'campaignCount' => 0,
                'usesSharedFunds' => false,
            ],
        ]);
    }
}
