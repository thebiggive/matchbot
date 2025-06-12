<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Charities;

use Doctrine\ORM\EntityManager;
use JetBrains\PhpStorm\Pure;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PostalAddress;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * @psalm-import-type SFCampaignApiResponse from \MatchBot\Client\Campaign
 */
class Put extends Action
{
    #[Pure]
    public function __construct(
        LoggerInterface $logger,
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

        if (!isset($requestBody['charity']) || !is_array($requestBody['charity'])) {
            throw new HttpBadRequestException($request, 'Missing or invalid charity data');
        }

        /** @var array<string, mixed> $charityData */
        $charityData = $requestBody['charity'];

        if (!isset($charityData['id']) || !is_string($charityData['id'])) {
            throw new HttpNotFoundException($request, 'Missing or invalid charity ID');
        }

        /** @var Salesforce18Id<Charity> $charitySfId */
        $charitySfId = Salesforce18Id::of($charityData['id']);

        if (!isset($args['salesforceId']) || !is_string($args['salesforceId'])) {
            throw new HttpBadRequestException($request, 'Missing or invalid salesforceId in URL');
        }

        Assertion::eq($charityData['id'], $args['salesforceId']);

        return $this->upsertCharity($charityData, $charitySfId);
    }

    /**
     * @param array<string, mixed> $charityData
     * @param Salesforce18Id<Charity> $charitySfId
     */
    public function upsertCharity(array $charityData, Salesforce18Id $charitySfId): JsonResponse
    {
        $charity = $this->charityRepository->findOneBySalesforceId($charitySfId);

        // Validate required fields
        Assertion::keyExists($charityData, 'name', 'Charity name is required');
        Assertion::keyExists($charityData, 'stripeAccountId', 'Stripe account ID is required');
        Assertion::keyExists($charityData, 'regulatorRegion', 'Regulator region is required');
        Assertion::keyExists($charityData, 'regulatorNumber', 'Regulator number is required');

        // Validate types for required fields
        Assertion::string($charityData['name'], 'Charity name must be a string');
        Assertion::string($charityData['stripeAccountId'], 'Stripe account ID must be a string');
        Assertion::string($charityData['regulatorRegion'], 'Regulator region must be a string');
        Assertion::string($charityData['regulatorNumber'], 'Regulator number must be a string');

        // Extract data
        $name = $charityData['name'];
        $stripeAccountId = $charityData['stripeAccountId'];
        $regulatorRegion = $charityData['regulatorRegion'];
        $regulatorNumber = $charityData['regulatorNumber'];

        // Optional fields
        $hmrcReferenceNumber = self::nullOrStringValue($charityData, 'hmrcReferenceNumber');
        $giftAidOnboardingStatus = self::nullOrStringValue($charityData, 'giftAidOnboardingStatus');
        $website = self::nullOrStringValue($charityData, 'website');
        $logoUri = self::nullOrStringValue($charityData, 'logoUri');
        $phoneNumber = self::nullOrStringValue($charityData, 'phoneNumber');

        // Get postal address data
        $address = $this->arrayToPostalAddress($charityData['postalAddress'] ?? null);

        // Get email address
        /** @var mixed $emailRaw */
        $emailRaw = $charityData['emailAddress'] ?? null;
        $emailString = is_string($emailRaw) ? $emailRaw : null;
        $emailAddress = $emailString !== null && trim($emailString) !== '' ? EmailAddress::of($emailString) : null;

        if (!$charity) {
            $charity = new Charity(
                salesforceId: $charitySfId->value,
                charityName: $name,
                stripeAccountId: $stripeAccountId,
                hmrcReferenceNumber: $hmrcReferenceNumber,
                giftAidOnboardingStatus: $giftAidOnboardingStatus,
                regulator: $this->getRegulatorHMRCIdentifier($regulatorRegion),
                regulatorNumber: $regulatorNumber,
                time: new \DateTime('now'),
                rawData: $charityData,
                websiteUri: $website,
                logoUri: $logoUri,
                phoneNumber: $phoneNumber,
                address: $address,
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
                regulator: $this->getRegulatorHMRCIdentifier($regulatorRegion),
                regulatorNumber: $regulatorNumber,
                rawData: $charityData,
                time: new \DateTime('now'),
                phoneNumber: $phoneNumber,
                address: $address,
                emailAddress: $emailAddress,
            );
            $this->logger->info("Updating charity {$charity->getSalesforceId()} from SF: {$charity->getName()}");
        }

        $this->entityManager->flush();

        return new JsonResponse([], 200);
    }

    protected function getRegulatorHMRCIdentifier(string $regulatorName): ?string
    {
        return match ($regulatorName) {
            'England and Wales' => 'CCEW',
            'Northern Ireland' => 'CCNI',
            'Scotland' => 'OSCR',
            default => null,
        };
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


    /**
     * Converts an array to a PostalAddress object.
     *
     * @param mixed $postalAddress The postal address data
     */
    private function arrayToPostalAddress(mixed $postalAddress): PostalAddress
    {
        if (!is_array($postalAddress)) {
            return PostalAddress::null();
        }

        // Convert empty strings to null and ensure all keys exist
        $keys = ['line1', 'line2', 'city', 'country', 'postalCode'];
        $cleanedAddress = [];

        foreach ($keys as $key) {
            /** @var mixed $rawValue */
            $rawValue = $postalAddress[$key] ?? null;
            $value = is_string($rawValue) ? $rawValue : null;
            $cleanedAddress[$key] = ($value !== null && trim($value) !== '') ? $value : null;
        }

        // Treat whole address as null if there's no `line1`
        if (is_null($cleanedAddress['line1'])) {
            // Check if any other fields are non-null
            $hasOtherFields = false;
            foreach ($keys as $key) {
                if ($key !== 'line1' && $cleanedAddress[$key] !== null) {
                    $hasOtherFields = true;
                    break;
                }
            }

            if ($hasOtherFields) {
                $this->logger->warning('Postal address from Salesforce is missing line1 but had other parts; treating as all-null');
            }

            return PostalAddress::null();
        }

        return PostalAddress::of(
            line1: $cleanedAddress['line1'],
            line2: $cleanedAddress['line2'],
            city: $cleanedAddress['city'],
            postalCode: $cleanedAddress['postalCode'],
            country: $cleanedAddress['country'],
        );
    }
}
