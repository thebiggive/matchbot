<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Symfony\Component\Serializer\SerializerInterface;

class Cancel extends Action
{
    /** @var DonationRepository */
    private $donationRepository;
    /** @var SerializerInterface */
    private $serializer;

    public function __construct(
        DonationRepository $donationRepository,
        LoggerInterface $logger,
        SerializerInterface $serializer
    ) {
        $this->donationRepository = $donationRepository;
        $this->serializer = $serializer;

        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        if (strlen($this->args['donationId']) !== 18) {
            throw new DomainRecordNotFoundException('Invalid donation ID');
        }

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['uuid' => $this->args['donationId']]);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        /** @var HttpModels\Donation $donationData */
        $donationData = $this->serializer->deserialize(
            $this->request->getBody(),
            HttpModels\Donation::class,
            'json'
        );

        if ($donationData->status !== 'Cancelled') {
            $error = new ActionError(ActionError::BAD_REQUEST, 'Only cancellations supported');

            return $this->respond(new ActionPayload(400, null, $error));
        }

        return $this->respondWithData($this->serializer->serialize($donation, 'json'));
    }
}
