<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;

class EmailVerificationTokenRepository
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - called by DI container.
     */
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findRecentTokenForEmailAddress(EmailAddress $emailAddress, \DateTimeImmutable $at): ?EmailVerificationToken
    {
        $emailVerificationToken = $this->em->createQuery(
            dql: <<<'DQL'
                SELECT t FROM MatchBot\Domain\EmailVerificationToken t
                WHERE t.emailAddress = :email
                AND t.createdAt > :created_since
                ORDER BY t.createdAt DESC
                DQL
        )->setParameters([
            'email' => $emailAddress->email,
            'created_since' => $at->sub(new \DateInterval('PT8H')),
        ])->setMaxResults(1)->getOneOrNullResult();

        \assert(is_null($emailVerificationToken) || $emailVerificationToken instanceof EmailVerificationToken);

        return $emailVerificationToken;
    }
}
