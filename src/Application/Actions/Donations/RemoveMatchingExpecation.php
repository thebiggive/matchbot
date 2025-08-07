<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Money;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records that the donor no longer expects any match funds to be used for their donation.
 * This allows matchbot to safely confirm the donation even if match funds have expired.
 */
class RemoveMatchingExpecation extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        \assert(isset($args['donationId']));
        $donation = $this->donationRepository->findAndLockOneByUUID(Uuid::fromString($args['donationId']));
        if (!$donation) {
            throw new NotFoundException();
        }

        $donation->setExpectedMatchAmount(Money::zero($donation->currency()));

        $this->entityManager->flush();

        $this->logger->info(
            sprintf(
                'Removed matching expectation for donation %s',
                $donation->getUuid()->toString()
            )
        );

        return new JsonResponse([
            'status' => 'success',
            'donation' => $donation->toFrontEndApiModel(),
        ]);
    }
}
