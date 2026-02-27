<?php

namespace MatchBot\Application\Actions\Donations;

use Assert\Assertion;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpNotFoundException;

/**
 * Provides a plain-text explanation of a donation and what happened with it for internal use by Big Give staff.
 */
class Explain extends  Action {
    public function __construct(
        LoggerInterface $logger,
        private DonationService $donationService,
        private DonationRepository $donationRepository,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (Environment::current() === Environment::Production) {
            // either work out how to do authentication and authorisation for BG staff (my preferred option - BL,
            // probably somehow by requiring them to be logged in to Salesforce first) or ensure there are no
            // confidential details output before removing this throw.
            throw new HttpNotFoundException($request);
        }

        Assertion::keyExists($args, "donationId");  // shoould always exist as is defined in routes.php
        $donationUUID = $args['donationId'];
        Assertion::string($donationUUID);
        if ($donationUUID === '') {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $donation = $this->donationRepository->findOneByUUID(Uuid::fromString($donationUUID));
        if (! $donation) {
            throw new DomainRecordNotFoundException('Missing donation');
        }


        $text = "Donation Details\n\n";

        $text .= "{$donation->getDescription()}\n--------------------------------------------------------\n\n\n";

        $i = 0;

        $donationDetails = $donation->toFrontEndApiModel() + $donation->toSFApiModel();

        ksort($donationDetails);

        $text .=
            $donationDetails
            |> (fn($d) => \array_map(function ($key, $value) use (&$i) {
                    $i++;
                    $value = json_encode($value);

                    return sprintf('%-40s %s', $key . ':', $value) . ($i % 5 === 0 ? "\n" : "");
                }, array_keys($d), $d)
            |> (fn($d) => \implode("\n", $d)));

        $response->getBody()->write($text);

        return $response->withHeader('content-type', 'text/plain');
    }
}
