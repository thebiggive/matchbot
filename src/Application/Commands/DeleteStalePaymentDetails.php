<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes payment methods from Stripe if:
 *   1. they (or more specifically, their Customer) are 24h+ old; and
 *   2. they do not belong to a Person with a password set after mid-April 2023; and
 *   3. they are a credit/debit card based method; and
 *   4. they have not been used for any complete donations.
 */
class DeleteStalePaymentDetails extends LockingCommand
{
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
        $stripePageSize = 100; // Maximum allowed. Iterators page through automatically.
        $customerCount = 0;
        $methodsDeleted = 0;
        $oneDayAgo = $this->initDate
            ->sub(new \DateInterval('P1D'))
            ->getTimestamp();

        // Get all Stripe customers without the password set metadata field, who are over 24h old.
        // The metadata restriction lets us better leave people with passwords since April 2023,
        // so this `query` covers conditions (1) and (2) from the class doc block.
        $customers = $this->stripeClient->customers->search([
            'query' => "created<$oneDayAgo and metadata['hasPasswordSince']:null",
            'limit' => $stripePageSize,
        ]);

        foreach ($customers->autoPagingIterator() as $customer) {
            $customerCount++;

            // Get all *card* type payment methods for this customer – condition (3).
            $paymentMethods = $this->stripeClient->paymentMethods->all([
                'customer' => $customer->id,
                'type' => 'card',
                'limit' => $stripePageSize,
            ]);

            foreach ($paymentMethods->autoPagingIterator() as $paymentMethod) {
                /** @var string $cardFingerprint    Only "card" type methods are queried, and every
                 *                                  card should have a string fingerprint.
                 */
                $cardFingerprint = $paymentMethod->card->fingerprint;

                // Check if this payment method has been used for any successful charges.
                // We may *query* (not list) charges and include a card's fingerprint (but
                // not ID).
                // https://stripe.com/docs/api/charges/search
                // https://stripe.com/docs/search#supported-query-fields-for-each-resource
                $charges = $this->stripeClient->charges->search([
                    'query' => sprintf(
                        'customer:"%s" and payment_method_details.card.fingerprint:"%s" and status:"succeeded"',
                        $customer->id,
                        $cardFingerprint,
                    ),
                    'limit' => $stripePageSize,
                ]);

                if ($charges->count() === 0) {
                    // Soft-delete / prevent reuse of the payment method.
                    $this->logger->info(sprintf(
                        'Detaching payment method %s, previously of customer %s',
                        $paymentMethod->id,
                        $customer->id,
                    ));
                    $this->stripeClient->paymentMethods->detach($paymentMethod->id);
                    $methodsDeleted++;
                }
            }
        }

        $output->writeln("Deleted $methodsDeleted payment methods from Stripe, having checked $customerCount customers");

        return 0;
    }
}
