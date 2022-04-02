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
        $donation = $this->donationRepository->findOneBy(['uuid' => $donationMessage->id]);

        if ($donationMessage->responseSuccess === false) {
            $donation->setTbgGiftAidRequestFailedAt(new \DateTime());
        }

        if ($donationMessage->responseSuccess === true) {
            $donation->setTbgGiftAidRequestConfirmedCompleteAt(new \DateTime());
        }

        if (!empty($donationMessage->submissionCorrelationId)) {
            $donation->setTbgGiftAidRequestCorrelationId($donationMessage->submissionCorrelationId);
        }

        if (!empty($donationMessage->responseDetail)) {
            $donation->setTbgGiftAidResponseDetail($donationMessage->responseDetail);
        }

        $this->entityManager->persist($donation);
        $this->entityManager->flush();
    }
}
