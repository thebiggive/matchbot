<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\MandateCreate;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\MandateService;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{
    public function __construct(
        private MandateService $mandateService,
        private Environment $environment,
        LoggerInterface $logger,
        private CampaignRepository $campaignRepository,
        private SerializerInterface $serializer,
        private EntityManagerInterface $em,
        private \DateTimeImmutable $now,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }

        $donorIdString = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        \assert(is_string($donorIdString));
        $body = (string) $request->getBody();

        try {
            $mandateData = $this->serializer->deserialize($body, MandateCreate::class, 'json');
            \assert($mandateData instanceof MandateCreate);
        } catch (\TypeError | UnexpectedValueException | AssertionFailedException $exception) {
            /** similar catch with commentary in @see \MatchBot\Application\Actions\Donations\Create */
            $this->logger->info("Mandate Create non-serialisable payload was: $body");

            $message = 'Donation Create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                response: $response,
                logMessage: "$message: $exceptionType - {$exception->getMessage()}",
                publicMessage: $message,
            );
        }

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandateData->campaignId);
        if (!$campaign) {
            // For a donation we would pull the campaign and charity from Salesforce at this point, but that adds
            // some complexity. For a regular giving mandate I'm hoping we can arrange for the campaigns and charities
            // to be in the Matchbot database in advance of any donor filling in the mandate form, e.g. using an SF
            // trigger or matchbot polling

            throw new HttpBadRequestException($request, 'Campaign not found');
        }

        $charity = $campaign->getCharity();

        // create donor account if not existing. For now we assume the donor account already exists in the Matchbot DB.

        $mandate = $this->mandateService->setupNewMandate(
            donorID: PersonId::of($donorIdString),
            amount: $mandateData->amount,
            campaign: $campaign,
            giftAid: $mandateData->giftAid,
            dayOfMonth: $mandateData->dayOfMonth,
        );

        // create first three pending donations for mandate.

        // throw if any donation is not fully matched, (unless the donor has told us that they're OK with making an
        // unmatched or partially matched donation)

        // tell stripe to take payment for first donation. Throw if payment fails synchronously.

        // another class will receive the event from stripe later to say first donation is collected, and
        // then activate the mandate (i.e. update it status using RegularGivingMandate::activate ) and email
        // the donor.

        // Return some details of the pending mandate to FE. FE will poll the mandate for the update to show
        // when the mandate is active.

        $this->em->flush();
        return new JsonResponse($mandate->toFrontEndApiModel($charity, $this->now), 201);
    }
}
