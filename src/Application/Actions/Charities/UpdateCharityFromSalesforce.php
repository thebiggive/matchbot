<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Charities;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class UpdateCharityFromSalesforce extends Action
{
    public function __construct(
        private CampaignRepository $campaignRepository,
        LoggerInterface $logger,
        private EntityManagerInterface $em,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $salesforceId = $args['salesforceId'] ?? null;

        if (! is_string($salesforceId)) {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        try {
            $sfId = Salesforce18Id::of($salesforceId);
        } catch (AssertionFailedException $e) {
            throw new HttpNotFoundException($request, $e->getMessage());
        }

        $campaignsToUpdate = $this->campaignRepository->findUpdatableForCharity($sfId);
        foreach ($campaignsToUpdate as $campaign) {
            // also implicitly updates the charity every time.
            $this->campaignRepository->updateFromSf($campaign);
        }

        $this->em->flush();

        $data = [];

        return $this->respondWithData($response, $data);
    }
}