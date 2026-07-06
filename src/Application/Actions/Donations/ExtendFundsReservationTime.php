<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Settings;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Extends the reservation time of a donation, if permissible. Will be required soon as we will only issue
 * reservations for five-minute duration, so calling this action repeatedly acts like a heartbeat so we know
 * the FE hasn't gone away.
 */
class ExtendFundsReservationTime extends Action
{
    private bool $enableNoReservationsMode;

    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        private ClockInterface $clock,
        Settings $settings,
    ) {
        parent::__construct($logger);
        $this->enableNoReservationsMode = $settings->enableNoReservationsMode;
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
        if (empty($args['donationId']) || ! is_string($args['donationId'])) {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $donationUUID = $args['donationId'];

        try {
            $donation = $this->donationRepository->findAndLockOneByUUID(Uuid::fromString($donationUUID));
        } catch (LockWaitTimeoutException $lockWaitTimeoutException) {
            $this->logger->warning(sprintf(
                'Caught LockWaitTimeoutException in Extend for donation %s',
                $donationUUID,
            ));

            throw new HttpBadRequestException(
                request: $request,
                message: 'Could not extend donation, locked by another request?',
                previous: $lockWaitTimeoutException
            );
        }

        if (! $donation) {
            throw new HttpNotFoundException($request, "Donation $donationUUID not found");
        }

        $donation->extendReservationFrom($this->clock->now());

        $this->entityManager->flush();

        return $this->respondWithData($response, $donation->toFrontEndApiModel($this->enableNoReservationsMode));
    }
}
