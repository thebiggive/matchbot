<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Charities;

use Doctrine\ORM\EntityManager;
use JetBrains\PhpStorm\Pure;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;

/**
 * @psalm-import-type SFCharityApiResponse from \MatchBot\Client\Campaign
 */
class UpsertMany extends Action
{
    #[Pure]
    public function __construct(
        LoggerInterface $logger,
        private CharityRepository $charityRepository,
        private EntityManager $entityManager,
        private Clock $clock,
        private LockFactory $lockFactory,
    ) {
        parent::__construct($logger);
    }

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

        if (!isset($requestBody['charities']) || !is_array($requestBody['charities'])) {
            throw new HttpBadRequestException($request, 'Missing or invalid charity data');
        }


        try {
            // we don't want to run this section twice at once in two threads because the ORM doens't actually do an
            // upsert - it checks for existence and then does an insert or update accordingly. If two threads
            // try to insert the same charity then one will throw a UniqueConstraintViolationException and close the EM
            $lock = $this->lockFactory->createLock(self::class, autoRelease: true);
            $lock->acquire(blocking: true);
        } catch (LockConflictedException | LockAcquiringException) {
            $this->logger->error("Could not aquire lock to upsert charities");
            return new JsonResponse("Could not aquire lock to upsert charities", 400);
        }

        /** @var SFCharityApiResponse $charityData */
        foreach ($requestBody['charities'] as $charityData) {
            $charitySfId = Salesforce18Id::ofCharity($charityData['id']);
            $this->upsertCharity($charityData, $charitySfId);
        }

        return new JsonResponse([], 200);
    }

    /**
     * @param SFCharityApiResponse $charityData
     * @param Salesforce18Id<Charity> $charitySfId
     */
    public function upsertCharity(array $charityData, Salesforce18Id $charitySfId): void
    {
        // Extract data
        $name = $charityData['name'];
        $stripeAccountId = $charityData['stripeAccountId'];
        $regulatorRegion = $charityData['regulatorRegion'];
        $regulatorNumber = self::nullOrStringValue($charityData, 'regulatorNumber');

        // Optional fields
        $hmrcReferenceNumber = self::nullOrStringValue($charityData, 'hmrcReferenceNumber');
        $giftAidOnboardingStatus = self::nullOrStringValue($charityData, 'giftAidOnboardingStatus');
        $website = self::nullOrStringValue($charityData, 'website');
        $logoUri = self::nullOrStringValue($charityData, 'logoUri');
        $phoneNumber = self::nullOrStringValue($charityData, 'phoneNumber');

        $emailRaw = $charityData['emailAddress'] ?? null;
        $emailString = is_string($emailRaw) ? $emailRaw : null;
        $emailAddress = $emailString !== null && trim($emailString) !== '' ? EmailAddress::of($emailString) : null;

        $charity = $this->charityRepository->findOneBySalesforceId($charitySfId);

        if (! $charity) {
            $charity = new Charity(
                salesforceId: $charitySfId->value,
                charityName: $name,
                stripeAccountId: $stripeAccountId,
                hmrcReferenceNumber: $hmrcReferenceNumber,
                giftAidOnboardingStatus: $giftAidOnboardingStatus,
                regulator: CampaignRepository::getRegulatorHMRCIdentifier($regulatorRegion),
                regulatorNumber: $regulatorNumber,
                time: \DateTime::createFromInterface($this->clock->now()),
                rawData: $charityData,
                websiteUri: $website,
                logoUri: $logoUri,
                phoneNumber: $phoneNumber,
                emailAddress: $emailAddress,
            );
            $this->entityManager->persist($charity);

            $this->logger->info("Saving new charity from SF: {$charity->getName()} {$charity->getSalesforceId()}");
        } else {
            $charity->updateFromSfPull(
                charityName: $name,
                websiteUri: $website,
                logoUri: $logoUri,
                stripeAccountId: $stripeAccountId,
                hmrcReferenceNumber: $hmrcReferenceNumber,
                giftAidOnboardingStatus: $giftAidOnboardingStatus,
                regulator: CampaignRepository::getRegulatorHMRCIdentifier($regulatorRegion),
                regulatorNumber: $regulatorNumber,
                rawData: $charityData,
                time: new \DateTime('now'),
                phoneNumber: $phoneNumber,
                emailAddress: $emailAddress,
            );
            $this->logger->info("Updating charity {$charity->getSalesforceId()} from SF: {$charity->getName()}");
        }

        $this->entityManager->flush();
    }

    /**
     * Returns a null or string value from an array.
     *
     * @param array<string, mixed> $data The array to extract the value from
     * @param string $key The key to extract
     * @return string|null The extracted value, or null if not present or not a string
     */
    private static function nullOrStringValue(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        Assertion::nullOrString($data[$key], "$key must be a string or null");
        return is_string($data[$key]) ? $data[$key] : null;
    }
}
