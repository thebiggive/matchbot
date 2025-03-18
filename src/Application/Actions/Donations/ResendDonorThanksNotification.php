<?php

namespace MatchBot\Application\Actions\Donations;

use Assert\Assertion;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\EmailAddress;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpBadRequestException;

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
        $UUID = $this->argToUuid($args, 'donationId');

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
        \assert(is_array($requestBody));

        $sendToEmailParam = $requestBody['sendToEmailAddress'] ?? null;
        \assert(is_string($sendToEmailParam) || is_null($sendToEmailParam));
        $toEmailAddress = (is_string($sendToEmailParam) && trim($sendToEmailParam) !== '') ?
            EmailAddress::of($sendToEmailParam) :
            null;

        $donation = $this->donationRepository->findOneByUUID($UUID);
        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        if (! $donation->getDonationStatus()->isSuccessful()) {
            throw new BadRequestException('Donation status is not successful');
        }

        $this->donationNotifier->notifyDonorOfDonationSuccess($donation, $toEmailAddress);

        return new JsonResponse(['message' => 'Notification sent to donor for ' . $donation->__toString()], 200);
    }
}
