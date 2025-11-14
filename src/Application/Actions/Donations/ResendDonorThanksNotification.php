<?php

namespace MatchBot\Application\Actions\Donations;

use Assert\Assertion;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccountRepository;
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
    public function __construct(
        private DonationRepository $donationRepository,
        private DonationNotifier $donationNotifier,
        private DonorAccountRepository $donorAccountRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $donationUUID = $this->argToUuid($args, 'donationId');

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

        $donation = $this->donationRepository->findOneByUUID($donationUUID);
        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        if (! $donation->getDonationStatus()->isSuccessful()) {
            throw new BadRequestException('Donation status is not successful');
        }

        // We can't invite donor to register based on an old donation - donation based registration only works
        // for new person records in identity and new email verification tokens.
        $sendRegisterUri = false;

        $showAccountExistsForEmail = $this->donorAccountRepository->accountExistsMatchingEmailWithDonation($donation);

        $this->donationNotifier->notifyDonorOfDonationSuccess(
            donation: $donation,
            sendRegisterUri: $sendRegisterUri,
            showAccountExistsForEmail: $showAccountExistsForEmail,
            to: $toEmailAddress,
        );

        return new JsonResponse(['message' => 'Notification sent to donor for ' . $donation->__toString()], 200);
    }
}
