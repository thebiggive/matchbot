<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-37 – patch 3 donations for one out of sync 2021 campaign, which doesn't have the transfer
 * IDs to automate it.
 */
final class Version20240319163541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Patch 3 donations' statuses";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                donationStatus = 'Paid',
                salesforcePushStatus = 'pending-update'
            WHERE
                campaign_id = 1989 AND
                donationStatus = 'Collected'
            LIMIT 3
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
