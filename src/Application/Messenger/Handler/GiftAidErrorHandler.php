<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Messages;
use Psr\Log\LoggerInterface;

class GiftAidErrorHandler
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
            'Donation ID %s Gift Aid claim failure reported by ClaimBot',
            $donationMessage->id,
        ));

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['uuid' => $donationMessage->id]);

        $donation->setTbgGiftAidRequestFailedAt(new \DateTime());

        $this->entityManager->persist($donation);
        $this->entityManager->flush();
    }
}
