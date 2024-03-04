<?php

namespace MatchBot\Application\Commands;

use Doctrine\DBAL\Connection;
use MatchBot\Domain\DonationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * We always intend to pay out donations to charities with 13 days. If there are any recent donations that we haven't
 * paid out after that time then there's something wrong, and we want to know about it. Normally Stripe should have
 * sent us an event to tell us that each donation was paid within this time.
 */
class UnPaidDonationCheck extends Command
{
    protected static $defaultName = 'matchbot:unpaid-donations-check';

    public function __construct(private Connection $connection, private LoggerInterface $logger)
    {
        parent::__construct();
    }
    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $collected = DonationStatus::Collected->value;

        /** @var list<array<string|\Stringable|scalar>>  */
        $recentUnpaidDonations = $this->connection->fetchAllAssociative(<<<SQL
            SELECT Donation.id, Donation.salesforceId, Donation.createdAt, 
                   Campaign.name as campaign_name, Charity.name as charity_name from Donation 
            JOIN Campaign on campaign_id = Campaign.id
            JOIN Charity on Campaign.charity_id = Charity.id
            WHERE 
            Donation.donationStatus = '$collected' 
            AND Donation.createdAt > (NOW() - INTERVAL 1 MONTH) -- we might want to change this to 14 days so if we run
                                                                -- daily we don't get repeat alerts about same donation.
            AND Donation.createdAt < (NOW() - INTERVAL 13 DAY); 
            SQL
        );

        if ($recentUnpaidDonations !== []) {
            $unpaidDonationsAsString = implode(
                "\n",
                array_map(fn($row) => implode(', ', $row), $recentUnpaidDonations)
            );

            $this->logger->error(
                "Recent donations collected but not paid out, should not generally happen: \n\n " .
                "$unpaidDonationsAsString"
            );
        } else {
            $this->logger->info(
                "No recent donations collected over 13 days ago but not paid out."
            );
        }

        return 0;
    }
}
