<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Messages;

class GiftAidErrorHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Messages\Donation $donationMessage): void
    {
        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['uuid' => $donationMessage->id]);

        $donation->setTbgGiftAidRequestFailedAt(new \DateTime());

        $this->entityManager->persist($donation);
        $this->entityManager->flush();
    }
}
