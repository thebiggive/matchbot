<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Stripe\Service\ChargeService;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentMethodService;
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
        private readonly StripeClient $stripeClient,
        private readonly \DateTimeImmutable $initDate,
    ) {
//        \assert($this->stripeClient instanceof StripeClient);
//        \assert($this->stripeClient->charges instanceof ChargeService);
//        \assert($this->stripeClient->customers instanceof CustomerService);
//        \assert($this->stripeClient->paymentMethods instanceof PaymentMethodService);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Deletes unused and likely-non-customer-deletable payment methods from Stripe');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
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
        ]);

        foreach ($customers->autoPagingIterator() as $customer) {
            $customerCount++;

            // Get all *card* type payment methods for this customer – condition (3).
            $paymentMethods = $this->stripeClient->paymentMethods->all([
                'customer' => $customer->id,
                'type' => 'card',
            ]);

            foreach ($paymentMethods->autoPagingIterator() as $paymentMethod) {
                // Check if this payment method has been used for any successful charges.
                $charges = $this->stripeClient->charges->all([
                    'payment_method' => $paymentMethod->id,
                    'status' => 'succeeded',
                ]);

                if ($charges->count() === 0) {
                    // Delete the payment method.
                    $this->stripeClient->paymentMethods->detach($paymentMethod->id);
                    $methodsDeleted++;
                }
            }
        }

        $output->writeln("Deleted $methodsDeleted payment methods from Stripe, having checked $customerCount customers");

        return 0;
    }
}
