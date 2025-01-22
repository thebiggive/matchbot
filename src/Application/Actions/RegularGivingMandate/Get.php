<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Assert\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\RegularGivingMandateRepository;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;

class Get extends Action
{
    public function __construct(
        private readonly RegularGivingMandateRepository $regularGivingMandateRepository,
        private readonly Environment $environment,
        private CampaignRepository $campaignRepository,
        private \DateTimeImmutable $now,
        LoggerInterface $logger,
        private Security $securityService,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }

        Assertion::keyExists($args, "mandateId");
        $mandateId = $args["mandateId"];
        Assertion::string($mandateId);
        if (empty($args['mandateId'])) {
            throw new DomainRecordNotFoundException('Missing mandate ID ' . $mandateId);
        }

        $authenticatedUser = $this->securityService->requireAuthenticatedDonorAccountWithPassword($request);

        $uuid = Uuid::fromString((string) $args['mandateId']);
        $mandate = $this->regularGivingMandateRepository->findOneByUuid($uuid);

        if ($mandate === null) {
            throw new DomainRecordNotFoundException('Mandate not found');
        }
        if (! $authenticatedUser->id()->equals($mandate->donorId())) {
            throw new HttpUnauthorizedException($request);
        }

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandate->getCampaignId());
        assert($campaign !== null);
        $charity = $campaign->getCharity();
        return new JsonResponse([
            'mandate' => $mandate->toFrontEndApiModel($charity, $this->now)
        ]);
    }
}
