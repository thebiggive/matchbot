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
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;

/**
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 */
class UpsertMany extends Action
{
    #[Pure]
    public function __construct(
        LoggerInterface $logger,
        private CampaignRepository $campaignRepository,
        private MetaCampaignRepository $metaCampaignRepository,
        private CharityRepository $charityRepository,
        private EntityManager $entityManager,
        private LockFactory $lockFactory,
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
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

        /** @var list<SFCampaignApiResponse> $multiCampaignData */
        $multiCampaignData = $requestBody['campaigns'];

        try {
            // we don't want to run this section twice at once in two threads because the ORM doens't actually do an
            // upsert - it checks for existence and then does an insert or update accordingly. If two threads
            // try to insert the same charity then one will throw a UniqueConstraintViolationException and close the EM
            $lock = $this->lockFactory->createLock(self::class, autoRelease: true);
            $lock->acquire(blocking: true);
        } catch (LockConflictedException | LockAcquiringException) {
            $this->logger->error("Could not aquire lock to upsert campaigns");
            return new JsonResponse("Could not aquire lock to upsert campaigns", 400);
        }

        foreach ($multiCampaignData as $campaignData) {
            /** @var Salesforce18Id<Campaign|MetaCampaign> $campaignSfId */
            $campaignSfId = Salesforce18Id::of(
                $campaignData['id'] ?? throw new HttpBadRequestException($request)
            );

            $isMetaCampaign = $campaignData['isMetaCampaign'];

            if ($isMetaCampaign) {
                /** @var Salesforce18Id<MetaCampaign> $campaignSfId */
                $this->upsertMetaCampaign($request, $campaignData, $campaignSfId);
            } else {
                /** @var Salesforce18Id<Campaign> $campaignSfId */
                $this->upsertCharityCampaign($campaignData, $campaignSfId);
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([], 200);
    }

    /**
     * @param SFCampaignApiResponse $campaignData
     * @param Salesforce18Id<Campaign> $campaignSfId
     */
    public function upsertCharityCampaign(array $campaignData, Salesforce18Id $campaignSfId): void
    {
        $charityData = $campaignData['charity'];
        Assertion::notNull($charityData, 'Charity data must not be null');

        $charitySfId = Salesforce18Id::ofCharity($charityData['id']);

        $campaign = $this->campaignRepository->findOneBySalesforceId($campaignSfId);
        $charity = $this->charityRepository->findOneBySalesforceId($charitySfId);

        if (!$charity) {
            throw new \Exception("Does not have a Charity record with the details: {$charityData['name']} {$charityData['id']} Campaign Details: {$campaignData['title']} {$campaignData['id']}");
        }
        // else we DO NOT update the charity here - for efficiency and clarity a separate action should be used to send
        // charity updates when they change, instead of updating the charity every time a campaign changes.

        if (!$campaign) {
            $campaign = Campaign::fromSfCampaignData($campaignData, $campaignSfId, $charity);
            $this->logger->info("Saving new campaign from SF: {$charity->getName()} {$charity->getSalesforceId()}");
            $this->entityManager->persist($campaign);
        } else {
            // don't update the charity, won't work if we are updating many at once in parallel and the charity gets updated separatley.
            $this->campaignRepository->updateCampaignFromSFData($campaign, $campaignData, alsoUpdateCharity: false);
            $this->logger->info("updating campaign {$campaign->getId()} from SF: {$charity->getName()} {$charity->getSalesforceId()}");
        }
    }


    /**
     * @param SFCampaignApiResponse $campaignData
     * @param Salesforce18Id<MetaCampaign> $campaignSfId
     */
    public function upsertMetaCampaign(Request $request, array $campaignData, Salesforce18Id $campaignSfId): void
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
    }
}
