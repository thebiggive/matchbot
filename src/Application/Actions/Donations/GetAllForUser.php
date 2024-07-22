<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Environment;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\StripeCustomerId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Returns all sucessful the donations for the logged in user
 */
class GetAllForUser extends Action
{
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private Environment $environment,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        $customerId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PSP_ATTRIBUTE_NAME);
        if (! is_string($customerId)) {
            throw new HttpBadRequestException($request, 'Missing customer ID');
        }


        $stripeCustomerId = StripeCustomerId::of($customerId);

        if (! $this->environment->isFeatureEnabledListPastDonations()) {
            throw new HttpNotFoundException($request);
        }

        $donations = $this->donationRepository->findAllCompleteForCustomer($stripeCustomerId);

        // this does expose more data than we currently need to display, but it's the same as was exposed in the API
        // at the time they created the donation, so nothing we mind the donor having access to.
        $apiModels = array_map(static fn(Donation $d) => $d->toFrontEndApiModel(), $donations);

        return $this->respondWithData($response, ['donations' => $apiModels]);
    }
}
