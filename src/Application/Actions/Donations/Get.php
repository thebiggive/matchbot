<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class Get extends Action
{
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     */
    protected function action(): Response
    {
        if (empty($this->args['donationId'])) { // When MatchBot made a donation, this is now a UUID
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        /** @var Donation $donation */
        $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $this->args['donationId']]);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        return $this->respondWithData($donation->toApiModel());
    }
}
