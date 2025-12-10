<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-472 Patch data for 2 donations where end of campaign processes set incorrect matching.
 */
final class Version20251210114851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Patch data for 2 donations where end of campaign processes set incorrect matching';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            DELETE FROM FundingWithdrawal
            WHERE (id = 1214697 AND amount = 4450)
                OR (id = 1214783 AND amount = 3110)
                LIMIT 2
        EOT);

        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'pending-update' WHERE id IN (1361106, 1366257)");
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
