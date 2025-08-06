<?php

namespace MatchBot\Application\Actions\MetaCampaigns;

use Assert\AssertionFailedException;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class Get extends Action
{
    public function __construct(
        private CampaignService $campaignService,
        private MetaCampaignRepository $metaCampaignRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        try {
            $slug = MetaCampaignSlug::of(
                $args['slug'] ?? throw new HttpNotFoundException($request)
            );
        } catch (AssertionFailedException) {
            throw new HttpNotFoundException($request);
        }

        $metaCampagin = $this->metaCampaignRepository->getBySlug($slug) ?? throw new HttpNotFoundException($request);

        return new JsonResponse([
            'metaCampaign' => $this->campaignService->renderMetaCampaign($metaCampagin),
        ]);
    }
}
