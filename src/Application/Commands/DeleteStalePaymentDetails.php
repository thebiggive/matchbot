<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Client\Stripe;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Psr\Log\LoggerInterface;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes payment methods from Stripe which are not useful to keep.
 */
class DeleteStalePaymentDetails extends LockingCommand
{
    private const int STRIPE_PAGE_SIZE = 100; // Maximum allowed. Iterators page through automatically.

    private const int MAX_CUSTOMER_COUNT_TO_DETATCH_PER_RUN = 2_000;

    protected static $defaultName = 'matchbot:delete-stale-payment-details';

    public function __construct(
        private readonly \DateTimeImmutable $initDate,
        private LoggerInterface $logger,
        private readonly Stripe $stripe,
        private DonorAccountRepository $donorAccountRepository,
    ) {
        parent::__construct();
    }

    /**
     * @param PaymentMethod $paymentMethod
     */
    private function paymentMethodMayBeUsedInFuture(?DonorAccount $donorAccount, mixed $paymentMethod, bool $customerHasPasswordSet): bool
    {
        $thisIsCustomersRGMethod =  StripePaymentMethodId::of($paymentMethod->id)->equals(
            $donorAccount?->getRegularGivingPaymentMethod()
        );

        return $customerHasPasswordSet && ($paymentMethod->allow_redisplay !== 'limited' || $thisIsCustomersRGMethod);
    }

    /**
     * @param iterable<array-key, PaymentMethod> $paymentMethods
     * @param Customer $customer
     */
    public function detachStaleMethods(
        iterable $paymentMethods,
        bool $isDryRun,
        Customer|\stdClass $customer,
        ?DonorAccount $donorAccount
    ): int {
        $customerHasPasswordSet = ! \is_null($donorAccount);

        $methodsDeleted = 0;

        foreach ($paymentMethods as $paymentMethod) {
            if ($this->paymentMethodMayBeUsedInFuture($donorAccount, $paymentMethod, $customerHasPasswordSet)) {
                // the customer may wish to use this method in future so do not detach it.
                continue;
            }

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

        // Get all Stripe customers who are over 24h old. This means that if they don't have a password now they never
        // will have, so we can treat their password or not status as final.
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

            $stripeAccountId = StripeCustomerId::of($customer->id);

            $paymentMethods = $this->stripe->listAllPaymentMethodsForCustomer($stripeAccountId, [
                'type' => 'card',
                'limit' => static::STRIPE_PAGE_SIZE,
            ]);

            /** @var \Generator<array-key, PaymentMethod>|array<PaymentMethod> $paymentMethodsIterator */
            $paymentMethodsIterator = $paymentMethods->autoPagingIterator();

            $methodsDeleted += $this->detachStaleMethods(
                $paymentMethodsIterator,
                $isDryRun,
                $customer,
                $this->donorAccountRepository->findByStripeIdOrNull($stripeAccountId)
            );
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

        try {
            $this->stripe->detatchPaymentMethod($paymentMethodId);
        } catch (ApiErrorException $e) {
            // likely just overlapping deletion attempts or similar. We'll retry deletion on the next run if still required.
            $this->logger->info("Error attempting to detach method $paymentMethod: " . $e->getMessage());
        }
    }
}
