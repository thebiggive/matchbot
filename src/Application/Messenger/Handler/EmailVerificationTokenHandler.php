<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManager;
use MatchBot\Domain\EmailVerificationToken;
use Messages\EmailVerificationToken as TokenMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class EmailVerificationTokenHandler
{
    public function __construct(
        private EntityManager $entityManager,
    ) {
    }

    public function __invoke(TokenMessage $message): void
    {
        $this->entityManager->persist(new EmailVerificationToken(
            randomCode: $message->randomCode,
            emailAddress: $message->emailAddress,
            createdAt: $message->createdAt
        ));

        $this->entityManager->flush();
    }
}
