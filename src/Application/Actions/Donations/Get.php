<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use function DI\value;

class Get extends Action
{
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private CampaignRepository $campaignRepository,
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
        if (empty($args['donationId'])) { // When MatchBot made a donation, this is now a UUID
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['uuid' => $args['donationId']]);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        $campaign = $this->campaignRepository->find($donation->getCampaignId()->value);
        \assert($campaign !== null);
        return $this->respondWithData($response, $donation->toApiModel($campaign));
    }
}
