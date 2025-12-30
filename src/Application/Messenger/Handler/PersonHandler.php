<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\StripeCustomerId;
use Messages\Person;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Saves new or updated Person info. Expected only for those with passwords and Stripe Customer
 * records.
 */
#[AsMessageHandler]
readonly class PersonHandler
{
    public function __construct(
        // apparently at the time this is constructed in tests the container isn't ready to give
        // us a donorAccountRepository, so taking a ref to the container instead and getting the
        // repository inside __invoke
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @psalm-suppress RedundantCondition - I prefer to keep the redundant conditions in the code here for clarity.
     */
    public function __invoke(Person $personMessage): void
    {
        $this->logger->info(sprintf(
            'Person ID %s data received',
            $personMessage->id,
        ));

        $donorAccountRepo = $this->container->get(DonorAccountRepository::class);
        $stripe = $this->container->get(Stripe::class);
        $donationRepository = $this->container->get(DonationRepository::class);
        $regularGivingMandateRepository = $this->container->get(RegularGivingMandateRepository::class);
        $clock = $this->container->get(ClockInterface::class);
        $em = $this->container->get(EntityManagerInterface::class);

        $donorAccount = $donorAccountRepo->findByStripeIdOrNull(
            StripeCustomerId::of($personMessage->stripe_customer_id),
        );

        if ($donorAccount !== null && ! $personMessage->deleted) {
            $this->logger->info(sprintf('Updating existing Person ID %s', $personMessage->id));
            $donorAccount->updateFromPersonMessage($personMessage);
        } elseif ($donorAccount === null && ! $personMessage->deleted) {
            $this->logger->info(sprintf('Creating new Person ID %s', $personMessage->id));
            $donorAccount = DonorAccount::fromPersonMessage($personMessage);
        } elseif ($donorAccount !== null && $personMessage->deleted) {
            if ($donationRepository->findAllCompleteForCustomer($donorAccount->stripeCustomerId) === []) {
                // as they have no donations and are deleting their account, we don't need to keep a record of them
                // in stripe (although in fact Stripe does retain records of deleted customers, with reduced
                // functionality).
                //
                // Given that the record is retained in stripe anyway, we could consider deleting the stripe customer
                // without reference to whether they have complete donations or not.

                $stripe->deleteCustomer($donorAccount->stripeCustomerId);

                $this->logger->info(sprintf(
                    'Stripe customer %s for person ID %s data deleted',
                    $donorAccount->stripeCustomerId->stripeCustomerId,
                    $personMessage->id,
                ));
            }
            $donorAccountRepo->delete($donorAccount);

            foreach ($regularGivingMandateRepository->allActiveMandatesForDonor($donorAccount->id()) as [$mandate, $_charity]) {
                $mandate->cancel('Donor account deleted', $clock->now(), MandateCancellationType::DonorAccountDeleted);
            }

            $this->logger->info(sprintf(
                'Person ID %s data deleted',
                $personMessage->id,
            ));

            $em->flush();

            return;
        } else {
            \assert($donorAccount === null && $personMessage->deleted);
            // no need to do anything.
            return;
        }

        $donorAccountRepo->save($donorAccount);

        $this->logger->info(sprintf(
            'Person ID %s data saved',
            $personMessage->id,
        ));
    }
}
