<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-3405 Re-push some donations following Salesforce outage
 */
final class Version20260922101206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-push some donations following Salesforce outage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update'
            WHERE updatedAt BETWEEN '2026-09-16 07:00:00' AND '2026-09-16 13:00:00'
                LIMIT 100 -- <- 100 is higher than the expected number of donations.
          EOT
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
