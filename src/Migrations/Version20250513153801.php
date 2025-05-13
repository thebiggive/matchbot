<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Patch a donation paid out late due to delays in fulfilling account requirements.
 */
final class Version20250513153801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Status patch for a May 2025 payout';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                donationStatus = 'Paid',
                salesforcePushStatus = 'pending-update'
            WHERE
                campaign_id = 7071 AND
                donationStatus = 'Collected' AND
                transactionId = 'pi_3PY6dQKkGuKkxwBN0FIn8LXn'
            LIMIT 1
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
