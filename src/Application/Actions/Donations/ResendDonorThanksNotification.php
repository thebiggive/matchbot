<?php

namespace MatchBot\Application\Actions\Donations;

use Assert\Assertion;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * For use by the Big Give (using SF as UI) when donors request a duplicate copy of a donation thanks email,
 * or a copy of an email for a regular giving donation, which they do not get automatically.
 */
class ResendDonorThanksNotification extends Action
{
    public function __construct(private DonationRepository $donationRepository, private DonationNotifier $donationNotifier, LoggerInterface $logger)
    {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        Assertion::keyExists($args, "donationId");
        $donationUUID = $args['donationId'];
        Assertion::string($donationUUID);
        if ($donationUUID === '') {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $donation = $this->donationRepository->findOneByUUID(Uuid::fromString($donationUUID));
        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        if (! $donation->getDonationStatus()->isSuccessful()) {
            throw new BadRequestException('Donation status is not successful');
        }

        $this->donationNotifier->notifyDonorOfDonationSuccess($donation);

        return new JsonResponse(['message' => 'Notification sent to donor for ' . $donation->__toString()], 200);
    }
}
