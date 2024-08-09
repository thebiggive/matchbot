<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Charities;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Environment;
use MatchBot\Client\CampaignNotReady;
use MatchBot\Client\NotFoundException;
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
        private Environment $environment,
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
            $sfId = Salesforce18Id::ofCharity($salesforceId);
        } catch (AssertionFailedException $e) {
            throw new HttpNotFoundException($request, $e->getMessage());
        }

        $campaignsToUpdate = $this->campaignRepository->findUpdatableForCharity($sfId);
        $atLeastOneCampaignUpdated = false;
        foreach ($campaignsToUpdate as $campaign) {
            // also implicitly updates the charity every time.
            try {
                $this->campaignRepository->updateFromSf($campaign);
                $atLeastOneCampaignUpdated = true;
            } catch (NotFoundException $e) {
                if ($this->environment === Environment::Production) {
                    // we don't expect to delete campaigns in prod
                    throw $e;
                }
            } catch (CampaignNotReady $e) {
                // but it is normal for campaigns in prod to go from ready to not ready, e.g. when the campaign period
                // ends.
                $this->logger->warning($e->getMessage() . "while updating charity " . $sfId->value);
            }
        }

        if (! $atLeastOneCampaignUpdated) {
            $this->logger->warning(
                "Could not update charity " . $sfId->value . "from salesforce as no-known updateable campaigns"
            );
        }

        $this->em->flush();

        $data = [];

        return $this->respondWithData($response, $data);
    }
}
