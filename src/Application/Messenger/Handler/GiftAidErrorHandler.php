<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;

class GiftAidErrorHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(Messenger\Donation $donationMessage): void
    {
        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['uuid' => $donationMessage->id]);

        $donation->setTbgGiftAidRequestFailedAt(new \DateTime());

        $this->entityManager->persist($donation);
        $this->entityManager->flush();
    }
}
