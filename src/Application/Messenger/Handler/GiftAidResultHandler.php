<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\SalesforceWriteProxy;
use Messages;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GiftAidResultHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Messages\Donation $donationMessage): void
    {
        $this->logger->info(sprintf(
            'Donation ID %s Gift Aid claim result returned by ClaimBot',
            $donationMessage->id,
        ));

        $this->entityManager->beginTransaction();

        /** @var Donation $donation */
        $donation = $this->donationRepository->findAndLockOneByUUID(Uuid::fromString($donationMessage->id));

        if ($donationMessage->response_success === false) {
            $donation->setTbgGiftAidRequestFailedAt(new \DateTime());
        }

        if ($donationMessage->response_success === true) {
            $donation->setTbgGiftAidRequestConfirmedCompleteAt(new \DateTime());
        }

        if ($donationMessage->submission_correlation_id !== null && $donationMessage->submission_correlation_id !== '') {
            $donation->setTbgGiftAidRequestCorrelationId($donationMessage->submission_correlation_id);
        }

        if ($donationMessage->response_detail !== null && $donationMessage->response_detail !== '') {
            $donation->setTbgGiftAidResponseDetail($donationMessage->response_detail);
        }

        $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);

        $this->entityManager->persist($donation);
        $this->entityManager->flush();
        $this->entityManager->commit();
    }
}
