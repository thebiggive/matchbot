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
    public function accountExistsMatchingEmailWithDonation(Donation $donation, LoggerInterface|null $logger = null): bool
    {
        $logger ??= new NullLogger();

        $logger->info('checking if account exists for donation ' . $donation->getUuid()->__toString());
        $emailAddress = $donation->getDonorEmailAddress();

        $logger->info('email address is ' . (string) $emailAddress?->email);

        if ($emailAddress === null) {
            return false;
        }

        $donorAccountForEmail = $this->findByEmail($emailAddress);

        $logger->info('found donor account ' . ($donorAccountForEmail?->id()->id->toString()));

        $donorIdFromDonation = $donation->getDonorId();

        $logger->info('donor ID from donation is ' . ($donorIdFromDonation?->id->toString()));

        if ($donorIdFromDonation == null) {
            // all donations made since April 2025 have non-null donorID.
            return $donorAccountForEmail !== null;
        }

        $return = $donorAccountForEmail !== null && !$donorAccountForEmail->id()->equals($donorIdFromDonation);

        $logger->info('returning ' . (string) $return);

        return $return;
    }
}
