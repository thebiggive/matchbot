<?php

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @extends EntityRepository<DonorAccount>
 */
class DonorAccountRepository extends EntityRepository
{
    /**
     * @throws UniqueConstraintViolationException if we already have a donor account with the same Stripe Customer ID.
     */
    public function save(DonorAccount $donorAccount, ?LoggerInterface $log = null): void
    {
        $log?->info('DON-1188: in \MatchBot\Domain\DonorAccountRepository::save');
        $this->getEntityManager()->persist($donorAccount);
        $log?->info('DON-1188: persisted donor account');
        $this->getEntityManager()->flush();
        $log?->info('DON-1188: flushed');
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
        $emailAddress = $donation->getDonorEmailAddress();

        if ($emailAddress === null) {
            return false;
        }

        $donorAccountForEmail = $this->findByEmail($emailAddress);

        $donorIdFromDonation = $donation->getDonorId();

        if ($donorIdFromDonation == null) {
            // all donations made since April 2025 have non-null donorID.
            return $donorAccountForEmail !== null;
        }

        return $donorAccountForEmail !== null && !$donorAccountForEmail->id()->equals($donorIdFromDonation);
    }

    public function delete(DonorAccount $donorAccount): void
    {
        $this->getEntityManager()->remove($donorAccount);
        $this->getEntityManager()->flush();
    }
}
