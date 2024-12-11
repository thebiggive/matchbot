<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\RegularGivingMandate;

use MatchBot\Application\Environment;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\RegularGivingMandateRepository;
use JetBrains\PhpStorm\Pure;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpNotFoundException;

class Get extends Action
{
    #[Pure]
    public function __construct(
        private readonly RegularGivingMandateRepository $regularGivingMandateRepository,
        private readonly Environment $environment,
        private CampaignRepository $campaignRepository,
        private \DateTimeImmutable $now,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }
        if (empty($args['mandateId'])) {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $donorId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        \assert($donorId instanceof PersonId);
        $uuid = Uuid::fromString((string) $args['mandateId']);
        $mandate = $this->regularGivingMandateRepository->findOneByUuid($uuid);

        if (!$mandate) {
            throw new DomainRecordNotFoundException('Mandate not found');
        }

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandate->getCampaignId());
        assert($campaign !==null);
        $charity = $campaign->getCharity();
        return new JsonResponse([
            'mandate' => $mandate->toFrontEndApiModel($charity, $this->now)
        ]);
    }
}
