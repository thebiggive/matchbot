<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\StripePaymentMethodId;
use Psr\Log\LoggerInterface;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes payment methods from Stripe if:
 *   1. they (or more specifically, their Customer) are 24h+ old; and
 *   2. they do not belong to a Person with a password set
 *   3. they are a credit/debit card based method; and
 */
class DeleteStalePaymentDetails extends LockingCommand
{
    private const int STRIPE_PAGE_SIZE = 100; // Maximum allowed. Iterators page through automatically.

    private const int MAX_CUSTOMER_COUNT_TO_DETATCH_PER_RUN = 2_000;

    protected static $defaultName = 'matchbot:delete-stale-payment-details';

    public function __construct(
        private readonly \DateTimeImmutable $initDate,
        private LoggerInterface $logger,
        private readonly \MatchBot\Client\Stripe $stripe,
    ) {
        parent::__construct();
    }

    /**
     * @param iterable<array-key, PaymentMethod> $paymentMethods
     * @param Customer $customer
     */
    public function detachStaleMethods(iterable $paymentMethods, bool $isDryRun, Customer|\stdClass $customer): int
    {
        $metadata = $customer->metadata;

        /** @psalm-suppress DocblockTypeContradiction */
        if ($metadata instanceof \stdClass) {
            // accounting for an awkard to change test.
            $metadataArray = (array) $metadata;
        } else {
            $metadataArray = $metadata?->toArray();
        }
        $customerHasPasswordSet = ($metadataArray['hasPasswordSince'] ?? null) !== null;

        $methodsDeleted = 0;

        foreach ($paymentMethods as $paymentMethod) {
            if ($customerHasPasswordSet && $paymentMethod->allow_redisplay === 'always') {
                // the customer may wish to use this method in future so do not detach it it.
                continue;
            }

            // we now know that the method is not useful - either the customer has no password
            // so they can not log in to use it, or they did not choose to have it redisplayed
            // so they don't want to use it.

            // In theory it could also be their selected regular giving method which we should
            // not delete so delete this code before closing ticket MAT-390 and definitely before
            // releasing the regular giving product - change back to only detach methods
            // for password-less customers once we have detached all the ones we don't need and
            // stopped adding any more.

            $this->detachPaymentMethod($isDryRun, $paymentMethod, $customer);
            $methodsDeleted++;
        }

        $this->stripe->updateCustomer(
            $customer->id,
            ['metadata' => ['paymentMethodsCleared' => $this->initDate->format('Y-m-d H:i:s')]]
        );

        return $methodsDeleted;
    }

    protected function configure(): void
    {
        $this->setDescription('Deletes unused and likely-non-customer-deletable payment methods from Stripe');
        $this->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $isDryRun = (bool) $input->getOption('dry-run');

        $customerCount = 0;
        $methodsDeleted = 0;
        $oneDayAgo = $this->initDate
            ->sub(new \DateInterval('P1D'))
            ->getTimestamp();

        // Get all Stripe customers without the password set metadata field, who are over 24h old.
        // The metadata restriction lets us better leave people with passwords since April 2023,
        // so this `query` covers conditions (1) and (2) from the class doc block.

        // temporarily including customers *with* passwords so we can iterate through them all once and
        // clear any payment methods that don't have allow_redisplay = 'always'. When ticket MAT-390 is
        // change below back to include
        //  'query' => "created<$oneDayAgo and metadata['hasPasswordSince']:null " .
        $customers = $this->stripe->searchCustomers([
            'query' => "created<$oneDayAgo " .
                "and metadata['paymentMethodsCleared']:null",
            'limit' => static::STRIPE_PAGE_SIZE,
        ]);

        /** @var Customer $customer */
        foreach ($customers->autoPagingIterator() as $customer) {
            if ($customerCount >= self::MAX_CUSTOMER_COUNT_TO_DETATCH_PER_RUN) {
                break;
            }

            $customerCount++;

            $paymentMethods = $this->stripe->listAllPaymentMethodsForTreasury([
                'customer' => $customer->id,
                'type' => 'card',
                'limit' => static::STRIPE_PAGE_SIZE,
            ]);

            $paymentMethodsIterator = $paymentMethods->autoPagingIterator();

            /**
             * @psalm-suppress MixedArgumentTypeCoercion - I don't understand this error, why is the type on the left not the parent?
             *   Argument 1 of MatchBot\Application\Commands\DeleteStalePaymentDetails::detachStaleMethods expects
             *       iterable<array-key, Stripe\PaymentMethod>, but parent type Generator|array<array-key, Stripe\PaymentMethod>
             *      provided (see https://psalm.dev/194)
             */
            $methodsDeleted += $this->detachStaleMethods($paymentMethodsIterator, $isDryRun, $customer);
        }

        $timeTaken = microtime(true) - $startTime;
        $timeTaken = round($timeTaken, 2);

        $output->writeln(
            $isDryRun ?
                "DRY RUN: Would have deleted $methodsDeleted payment methods from Stripe, having checked " .
                "$customerCount customers. Time Taken: {$timeTaken}s" :
            "Deleted $methodsDeleted payment methods from Stripe, having checked " .
                "$customerCount customers. Time Taken: {$timeTaken}s"
        );

        return 0;
    }

    /**
     * PHP types are wider than docblock types as we have some tests that pass stdClasses that are awkward to change
     * now. Actual stripe library returns PaymentMethod and Customer instances.
     *
     * @param PaymentMethod $paymentMethod
     * @param Customer $customer
     */
    public function detachPaymentMethod(
        bool $isDryRun,
        PaymentMethod|\stdClass $paymentMethod,
        Customer|\stdClass $customer
    ): void {
        $paymentMethodId = StripePaymentMethodId::of($paymentMethod->id);

        if ($isDryRun) {
            $this->logger->info(sprintf(
                'DRY RUN: full run would detach payment method %s, from customer %s',
                $paymentMethodId->stripePaymentMethodId,
                $customer->id,
            ));

            return;
        }

        $this->logger->info(sprintf(
            'Detaching payment method %s, previously of customer %s',
            $paymentMethodId->stripePaymentMethodId,
            $customer->id,
        ));
        $this->stripe->detatchPaymentMethod($paymentMethodId);
    }
}
