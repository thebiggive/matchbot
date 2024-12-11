<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Assert\Assertion;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class Get extends Action
{
    #[Pure]
    public function __construct(
        LoggerInterface $logger,
        private DonationService $donationService,
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        Assertion::keyExists($args, "donationId");  // shoould always exist as is defined in routes.php
        $donationUUID = $args['donationId'];
        Assertion::string($donationUUID);
        if ($donationUUID === '') {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $toFrontEndApiModel = $this->donationService->donationAsApiModel($donationUUID);

        return $this->respondWithData($response, $toFrontEndApiModel);
    }
}
