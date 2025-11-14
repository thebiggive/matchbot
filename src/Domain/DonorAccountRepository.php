<?php

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * @extends EntityRepository<DonorAccount>
 */
class DonorAccountRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, ClassMetadata $class, private LoggerInterface $logger)
    {
        parent::__construct($em, $class);
    }

    /**
     * @throws UniqueConstraintViolationException if we already have a donor account with the same Stripe Customer ID.
     */
    public function save(DonorAccount $donorAccount): void
    {
        $this->getEntityManager()->persist($donorAccount);
        $this->getEntityManager()->flush();
    }

    public function findByStripeIdOrNull(StripeCustomerId $stripeAccountId): ?DonorAccount
    {
        // see https://github.com/laravel-doctrine/fluent/issues/51 for using findOneBy on a field of an embeddable.
        return $this->findOneBy(['stripeCustomerId.stripeCustomerId' => $stripeAccountId->stripeCustomerId]);
    }

    public function findByPersonId(PersonId $personId): ?DonorAccount
    {
        return $this->findOneBy(['uuid' => $personId->id]);
    }

    public function findByEmail(EmailAddress $emailAddress): ?DonorAccount
    {
        return $this->findOneBy(['emailAddress.email' => $emailAddress->email]);
    }

    /**
     * @return bool Whether there is a donor account registered that has the same email address as this donation but
     * was not used to make the donation - i.e. so we can invite the donor to log in to it next time as they didn't when
     * making this donation.
     */
    public function accountExistsMatchingEmailWithDonation(Donation $donation): bool
    {
        $this->logger->info('checking if account exists for donation ' . $donation->getUuid()->__toString());
        $emailAddress = $donation->getDonorEmailAddress();

        $this->logger->info('email address is ' . (string) $emailAddress->email);

        if ($emailAddress === null) {
            return false;
        }

        $donorAccountForEmail = $this->findByEmail($emailAddress);

        $this->logger->info('found donor account ' . (string) ($donorAccountForEmail?->id()->id->toString()));

        $donorIdFromDonation = $donation->getDonorId();

        $this->logger->info('donor ID from donation is ' . (string) ($donorIdFromDonation?->id->toString()));

        if ($donorIdFromDonation == null) {
            // all donations made since April 2025 have non-null donorID.
            return $donorAccountForEmail !== null;
        }

        $return = $donorAccountForEmail !== null && !$donorAccountForEmail->id()->equals($donorIdFromDonation);

        $this->logger->info('returning ' . $return);

        return $return;
    }
}
