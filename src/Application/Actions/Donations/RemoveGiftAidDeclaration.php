<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManager;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DomainException\CannotRemoveGiftAid;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Chatter;
use Symfony\Component\Notifier\ChatterInterface;

class RemoveGiftAidDeclaration extends Action
{
    public function __construct(
        private DonationRepository $donationRepository,
        private Clock $clock,
        private RoutableMessageBus $bus,
        private EntityManager $entityManager,
        private ChatterInterface $chatter,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $donationUUID = $this->argToUuid($args, 'donationId');

        $donation = $this->donationRepository->findOneByUUID($donationUUID);
        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        try {
            $donation->removeGiftAid($this->clock->now());
            $this->entityManager->flush();
            $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
        } catch (CannotRemoveGiftAid $exception) {
            // we can send the message back to SF but no-one will see it there, so also send to Stripe slack channel.

            $this->chatter->send($this->prepareSlackMessage(
                heading: 'Could not remove GA from donation',
                body: $exception->getMessage()
            ));

            return $this->validationError(
                $response,
                $exception->getMessage(),
            );
        }

        return new JsonResponse([]);
    }
}
