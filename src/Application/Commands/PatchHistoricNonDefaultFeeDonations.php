<?php

namespace MatchBot\Application\Commands;

use Doctrine\DBAL\Connection;
use MatchBot\Application\Actions\Hooks\Stripe;
use MatchBot\Application\Assertion;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\SalesforceWriteProxy;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('matchbot:patchHistoricNonDefaultFeeDonations')]
class PatchHistoricNonDefaultFeeDonations extends Command
{
    public const REDIS_KEY = self::class . "-last-id-patched";

    public function __construct(private \Redis $redis, private Connection $connection, private StripeClient $stripe)
    {
        parent::__construct(null);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $idOfLastDonationPatched = $this->redis->get(self::REDIS_KEY);

        if (! $idOfLastDonationPatched) {
            $idOfLastDonationPatched = '0';
        }
        \assert(is_string($idOfLastDonationPatched));

        /** @var list<array{id: int, uuid: string, chargeId: string}> $donationDataToPatch */
        $donationDataToPatch = $this->connection->fetchAllAssociative(
            "SELECT id, Donation.chargeId, Donation.uuid from Donation 
                             WHERE donationStatus in ('Paid', 'Collected')
                                 AND paymentMethodType = 'card'
                                 AND createdAt > '2023-09-22' -- commit 5860113 of this date introduced buggy confirm function
                                 AND createdAt < '2023-11-23' -- hopefully the bug will have been fixed before that date.
                                 AND id > :idOfLastDonationPatched 
                                 ORDER BY id
                                 LIMIT 500",
            ['idOfLastDonationPatched' => $idOfLastDonationPatched]
        );

        foreach ($donationDataToPatch as $donation) {
            $output->writeln("updating donation " . $donation['id']);

            $chargeId = $donation['chargeId'];
            $metadata = $this->stripe->charges->retrieve($chargeId)->toArray()['metadata'];
            \assert(is_array($metadata));
            /** @var string $stripeFeeRechargeNet */
            $stripeFeeRechargeNet = $metadata['stripeFeeRechargeNet'];

            /** @var string $stripeFeeRechargeVat */
            $stripeFeeRechargeVat = $metadata['stripeFeeRechargeVat'];

            $updateData = [
                'donationUuid' => $donation['uuid'],
                'charityFee' => $stripeFeeRechargeNet,
                'charityFeeVat' => $stripeFeeRechargeVat,
            ];
            $rowsAffected = $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE Donation set charityFee = :charityFee, charityFeeVat = :charityFeeVat
                                    WHERE uuid = :donationUuid
                                    LIMIT 1
                    SQL
                ,
                $updateData
            );

            Assertion::inArray($rowsAffected, [0, 1]);
            match ($rowsAffected) {
                0 => $output->writeln("Donation data already matches stripe, nothing to update"),
                1 => $output->writeln("Donation data updated: uuid: {$donation['uuid']}, charityFee $stripeFeeRechargeNet, charityFeeVat $stripeFeeRechargeVat"),
            };

            if ($rowsAffected === 1) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    UPDATE Donation set salesforcePushStatus = :salesforcePushStatus
                                    WHERE uuid = :donationUuid
                                    LIMIT 1
                    SQL
                    ,
                    [
                        'donationUuid' => $donation['uuid'],
                        'salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE
                    ]
                );
            }

            $output->writeln('');
        }

        if (isset($donation)) {
            \assert(is_array($donation));
            $this->redis->set(self::REDIS_KEY, (string)$donation['id']);
            $count = count($donationDataToPatch);
            $output->writeln("Updated data for all $count donations between ID > $idOfLastDonationPatched <= {$donation['id']}");
        } else {
            $output->writeln("No donations found to update - if this is prod then this command is ready to be deleted.");
        }

        return 0;
    }
}