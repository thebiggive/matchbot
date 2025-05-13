<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-28 prepare donations for claiming to test claims with more than 10 donations per claim
 */
final class Version20220624112013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CLA-28 prepare donations for claiming to test claims with more than 10 donations per claim';
    }

    public function up(Schema $schema): void
    {
        // This DB migration is only to be ran in staging!
        if (getenv('APP_ENV') === 'staging') {
            // Add hmrcReferenceNumber in MatchBot DB
            $this->addSql(<<<EOT
                UPDATE Charity
                SET hmrcReferenceNumber = 'AB98765'
                WHERE salesforceId = '0011r00002HoeprAAB'
                LIMIT 1
            EOT);

            // Prepare donations for ClaimBot
            $this->addSql(<<<EOT
                UPDATE Donation
                SET donationStatus = 'Paid',
                    tbgShouldProcessGiftAid = 1
                WHERE campaign_id = 1781
                    AND donationStatus = 'Collected'
                    AND tbgShouldProcessGiftAid = 0
                    AND donorEmailAddress = 'Dominique@thebiggive.org.uk'
                    AND collectedAt >= '2022-06-01 00:00:00'
                    AND collectedAt <= '2022-06-01 23:59:59'
                LIMIT 12;
                EOT
            );
        }
    }

    public function down(Schema $schema): void
    {
        // nothing here
    }
}
