<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Campaigns;

use Doctrine\ORM\EntityManager;
use JetBrains\PhpStorm\Pure;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\Campaign as CampaignHTTPModel;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use MatchBot\Client;

/**
 *
 * todo - delete entire class as has been replaced by UpsertMany
 *
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 *
 * TODO:: Can be deleted later as we already created a {@see \MatchBot\Application\Actions\Campaigns\UpsertMany} POST method to accept multiple campaigns from SF.
 */
class Put extends Action
{
    #[Pure]
    public function __construct(
        LoggerInterface $logger,
        private CampaignRepository $campaignRepository,
        private MetaCampaignRepository $metaCampaignRepository,
        private CharityRepository $charityRepository,
        private EntityManager $entityManager,
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        // not yet ready for use in prod
        if (Environment::current()->isProduction()) {
            throw new HttpNotFoundException($request);
        }

        try {
            $requestBody = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(\is_array($requestBody));

        /** @var SFCampaignApiResponse $campaignData */
        $campaignData = $requestBody['campaign'];

        /** @var Salesforce18Id<Campaign|MetaCampaign> $campaignSfId */
        $campaignSfId = Salesforce18Id::of(
            $campaignData['id'] ?? throw new HttpNotFoundException($request)
        );


        Assertion::eq($campaignData['id'], $args['salesforceId']);

        $isMetaCampaign = $campaignData['isMetaCampaign'];

        if ($isMetaCampaign) {
            /** @var Salesforce18Id<MetaCampaign> $campaignSfId */
            return $this->upsertMetaCampaign($request, $campaignData, $campaignSfId);
        } else {
            /** @var Salesforce18Id<Campaign> $campaignSfId */
            return $this->upsertCharityCampaign($campaignData, $campaignSfId);
        }
    }

    /**
     * @param SFCampaignApiResponse $campaignData
     * @param Salesforce18Id<Campaign> $campaignSfId
     */
    public function upsertCharityCampaign(array $campaignData, Salesforce18Id $campaignSfId): JsonResponse
    {
        $charityData = $campaignData['charity'];
        Assertion::notNull($charityData, 'Charity data must not be null');

        $charitySfId = Salesforce18Id::ofCharity($charityData['id']);

        $campaign = $this->campaignRepository->findOneBySalesforceId($campaignSfId);
        $charity = $this->charityRepository->findOneBySalesforceId($charitySfId);

        if (!$charity) {
            $charity = $this->campaignRepository->newCharityFromCampaignData($campaignData);
            $this->entityManager->persist($charity);

            $this->logger->info("Saving new charity from SF: {$charity->getName()} {$charity->getSalesforceId()}");
        }
        // else we DO NOT update the charity here - for efficiency and clarity a separate action should be used to send
        // charity updates when they change, instead of updating the charity every time a campaign changes.

        if (!$campaign) {
            $campaign = Campaign::fromSfCampaignData($campaignData, $campaignSfId, $charity);
            $this->logger->info("Saving new campaign from SF: {$charity->getName()} {$charity->getSalesforceId()}");
            $this->entityManager->persist($campaign);
        } else {
            $this->campaignRepository->updateCampaignFromSFData($campaign, $campaignData);
            $this->logger->info("updating campaign {$campaign->getId()} from SF: {$charity->getName()} {$charity->getSalesforceId()}");
        }

        $this->entityManager->flush();

        return new JsonResponse([], 200);
    }


    /**
     * @param SFCampaignApiResponse $campaignData
     * @param Salesforce18Id<MetaCampaign> $campaignSfId
     */
    public function upsertMetaCampaign(Request $request, array $campaignData, Salesforce18Id $campaignSfId): JsonResponse
    {
        $metaCampaign = $this->metaCampaignRepository->findOneBySalesforceId($campaignSfId);
        $slug = MetaCampaignSlug::of(
            $campaignData['slug'] ?? throw new HttpBadRequestException($request, 'slug required')
        );

        if (!$metaCampaign) {
            $metaCampaign = MetaCampaign::fromSfCampaignData($slug, $campaignData);
            $this->logger->info("Saving new meta campaign from SF: {$metaCampaign->getTitle()} {$metaCampaign->getSalesforceId()}");
            $this->entityManager->persist($metaCampaign);
        } else {
            $metaCampaign->updateFromSfData($campaignData);
            $this->logger->info("updating meta campaign {$metaCampaign->getId()} from SF: {$metaCampaign->getTitle()} {$metaCampaign->getSalesforceId()}");
        }

        $this->entityManager->flush();

        return new JsonResponse([], 200);
    }
}
