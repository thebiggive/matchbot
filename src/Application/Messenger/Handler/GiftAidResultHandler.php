<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Messages;
use Psr\Log\LoggerInterface;

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

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneWithLockBy(['uuid' => $donationMessage->id]);

        if ($donationMessage->response_success === false) {
            $donation->setTbgGiftAidRequestFailedAt(new \DateTime());
        }

        if ($donationMessage->response_success === true) {
            $donation->setTbgGiftAidRequestConfirmedCompleteAt(new \DateTime());
        }

        if (!empty($donationMessage->submission_correlation_id)) {
            $donation->setTbgGiftAidRequestCorrelationId($donationMessage->submission_correlation_id);
        }

        if (!empty($donationMessage->response_detail)) {
            $donation->setTbgGiftAidResponseDetail($donationMessage->response_detail);
        }

        $this->entityManager->persist($donation);
        $this->entityManager->flush();
    }
}
