<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-387 – patch one campaign's donations to Paid, missed due to a failed payout and unusually late subsequent payout.
 */
final class Version20241112113957 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Status patch for an October 2024 payout';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                donationStatus = 'Paid',
                salesforcePushStatus = 'pending-update'
            WHERE
                campaign_id = 5936 AND
                donationStatus = 'Collected'
            LIMIT 30
        SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
