<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use JetBrains\PhpStorm\Pure;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\RefundScope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Stripe\Exception\ApiErrorException;

/**
 * Apply a donor-authorised PUT action to update an existing donation. The purpose
 * of the update can be to cancel the donation, add more details to it, or
 * confirm it if no further method info is needed (e.g. `customer_balance`
 * settlements).
 */
class Refund extends Action
{
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $args
     * @return Response
     * @throws DomainRecordNotFoundException on missing donation
     * @throws ApiErrorException if Stripe Payment Intent confirm() fails, other than because of a
     *                           missing payment method.
     */
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

        $refundScope = $requestBody['scope'] ?? null;
        \assert(in_array($refundScope, ['tip', 'full'], true));

        $donation = $this->donationRepository->findOneByUUID($donationUUID);
        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        if (!$donation->getDonationStatus()->isSuccessful()) {
            throw new BadRequestException('Donation status is not successful');
        }

        if ($donation->getDonationStatus()->isReversed()) {
            throw new BadRequestException('Donation is already refunded or similar');
        }

        $this->donationService->refund($donation, RefundScope::from($refundScope));

        $summary = sprintf("Refund (%s) processed for %s", $refundScope, $donation->__toString());

        return new JsonResponse(['message' => $summary], 200);
    }
}
