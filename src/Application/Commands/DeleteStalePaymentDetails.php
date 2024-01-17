<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Psr\Log\LoggerInterface;
use Stripe\Person;
use Stripe\StripeClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes payment methods from Stripe if:
 *   1. they (or more specifically, their Customer) are 24h+ old; and
 *   2. they do not belong to a Person with a password set
 *   3. they are a credit/debit card based method; and
 */
class DeleteStalePaymentDetails extends LockingCommand
{
    private const STRIPE_PAGE_SIZE = 100; // Maximum allowed. Iterators page through automatically.

    private const MAX_CUSTOMER_COUNT_TO_DETATCH_PER_RUN = 2_000;

    protected static $defaultName = 'matchbot:delete-stale-payment-details';

    public function __construct(
        private readonly \DateTimeImmutable $initDate,
        private LoggerInterface $logger,
        private readonly StripeClient $stripeClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Deletes unused and likely-non-customer-deletable payment methods from Stripe');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $customerCount = 0;
        $methodsDeleted = 0;
        $oneDayAgo = $this->initDate
            ->sub(new \DateInterval('P1D'))
            ->getTimestamp();

        // Get all Stripe customers without the password set metadata field, who are over 24h old.
        // The metadata restriction lets us better leave people with passwords since April 2023,
        // so this `query` covers conditions (1) and (2) from the class doc block.
        $customers = $this->stripeClient->customers->search([
            'query' => "created<$oneDayAgo and metadata['hasPasswordSince']:null " .
                "and metadata['paymentMethodsCleared']:null",
            'limit' => static::STRIPE_PAGE_SIZE,
        ]);

        foreach ($customers->autoPagingIterator() as $customer) {
            if ($customerCount >= self::MAX_CUSTOMER_COUNT_TO_DETATCH_PER_RUN) {
                break;
            }

            $customerCount++;

            // Get all *card* type payment methods for this customer – condition (3).
            $paymentMethods = $this->stripeClient->paymentMethods->all([
                'customer' => $customer->id,
                'type' => 'card',
                'limit' => static::STRIPE_PAGE_SIZE,
            ]);

            foreach ($paymentMethods->autoPagingIterator() as $paymentMethod) {
                // Soft-delete / prevent reuse of the payment method.
                $this->logger->info(sprintf(
                    'Detaching payment method %s, previously of customer %s',
                    $paymentMethod->id,
                    $customer->id,
                ));
                $this->stripeClient->paymentMethods->detach($paymentMethod->id);
                $methodsDeleted++;
            }

            $this->stripeClient->customers->update(
                $customer->id,
                ['metadata' => ['paymentMethodsCleared' => $this->initDate->format('Y-m-d H:i:s')]]
            );
        }

        $timeTaken = microtime(true) - $startTime;
        $timeTaken = round($timeTaken, 2);

        $output->writeln(
            "Deleted $methodsDeleted payment methods from Stripe, having checked " .
                "$customerCount customers. Time Taken: {$timeTaken}s"
        );

        /**
         * This has nothing to do with deleting stale payment details, but it's convenient to call it here just
         * because this is a function that runs every couple of hours. It's a bit messy but will be deleted once
         * it's run once in prod.
         */
        $this->clearAccountRep();

        return 0;
    }

    /**
     * One off tempoary function to clear an account rep for a connected account. Let this run once in production and
     * then delete. It's not possible to do the same thing without using the API. Doesn't matter if it runs a few times.
     *
     * See https://stripe.com/docs/connect/update-verified-information?locale=en-GB#change-the-account-representative
     */
    private function clearAccountRep(): void
    {
        $relaventAccountId = 'acct_1JKj2g4EM6tmvjBM';

        $peopleOnAccount = $this->stripeClient->accounts->allPersons($relaventAccountId);

        foreach ($peopleOnAccount as $person) {
            \assert($person instanceof \Stripe\Person);


            $person->updateAttributes(
                ['relationship' => ['representative' => false]]
            );

            $this->logger->info(
                "Updated relationship rep to false for person id #{$person->id} " .
                "on account $relaventAccountId"
            );
        }
    }
}
