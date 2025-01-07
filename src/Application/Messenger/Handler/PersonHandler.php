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
    /** @psalm-suppress PossiblyUnusedMethod Used by Messenger mapping & tests. */
    public function __construct(
        // apparently at the time this is constructed in tests the container isn't ready to give
        // us a donorAccountRepository, so taking a ref to the container instead and getting the
        // repository inside __invoke
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

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

        if ($donorAccount !== null) {
            $this->logger->info(sprintf('Updating existing Person ID %s', $personMessage->id));
            $donorAccount->updateFromPersonMessage($personMessage);
        } else {
            $this->logger->info(sprintf('Creating new Person ID %s', $personMessage->id));
            $donorAccount = DonorAccount::fromPersonMessage($personMessage);
        }

        $donorAccountRepo->save($donorAccount);

        $this->logger->info(sprintf(
            'Person ID %s data saved',
            $personMessage->id,
        ));
    }
}
