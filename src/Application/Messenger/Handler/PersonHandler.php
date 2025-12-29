<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\StripeCustomerId;
use Messages\Person;
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
            $donorAccountRepo->delete($donorAccount);
            $this->logger->info(sprintf(
                'Person ID %s data deleted',
                $personMessage->id,
            ));
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
