<?php

namespace MatchBot\Application\Commands;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MarkOldPasswordedAccountsInStripe extends LockingCommand
{
    public const IDENTITY_DBAL_CONNECTION_SERVICE_NAME = 'identity_dbal_connection';
    public const REDIS_KEY = 'password-push-to-stripe-completed-up-to';

    protected static $defaultName = 'matchbot:mark-old-passworded-accounts-in-stripe';
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        private readonly StripeClient $stripeClient,
        private \Redis $redis,
        private Connection $identityDBConnection,
    ) {
        $this->logger = $logger;
        $this->setLogger($logger);
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $completedUpTo = $this->redis->get(self::REDIS_KEY);
        if (! is_string($completedUpTo)) {
            $completedUpTo = "1970-01-01 00:00:00";
        }

        $personRows = $this->identityDBConnection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(id) as uuid, updated_at, stripe_customer_id, email_address from Person
                       where Password IS NOT NULL AND created_at >= :completed_up_to
                       AND Person.created_at < "2023-04-19" -- We dont need to do accounts from after this date as the password status is already in stripe.
                                                            -- see https://github.com/thebiggive/identity/blob/443c4cbb2589f99de4709172104b99ad2ee7c5d6/src/Application/Actions/Person/Update.php#L213
                       ORDER BY Person.created_at ASC
                       LIMIT 200000 -- should be enough to complete over four runs
                       ',
            ['completed_up_to' => $completedUpTo]
        );

        foreach($personRows as $row) {
            \assert(is_string($row['stripe_customer_id']));
            \assert(is_string($row['uuid']));
            \assert(is_string($row['email_address']));

            usleep(20_000);

            $this->stripeClient->customers->update($row['stripe_customer_id'], ['metadata' => ['hasPasswordSince' => $row['updated_at'], 'emailAddress' => $row['email_address']]]);
            $this->logger->info("Set password metadata in stripe for user " . $row['uuid']);
        }

        if (isset($row)) {
            \assert(is_array($row) && is_string($row['updated_at']));
            $completedUpTo = $row['updated_at'];
            $this->redis->set(self::REDIS_KEY, $completedUpTo);

            $this->logger->info(
                sprintf("sent password status info to stripe for %s accounts", count($personRows))
            );
            $this->logger->info(
                "Completed sending password status data to Stripe for all accounts updated since $completedUpTo, last account ID {$row['stripe_customer_id']}"
            );
        } else {
            $this->logger->info("No accounts remaining requiring password status sent to stripe. All completed up to $completedUpTo. This command may now be deleted from code.");
        }

        return 0;
    }
}