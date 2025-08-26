<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2907 – Delete old Funds no longer in use.
 */
final class Version20250826151307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BG2-2907 – Delete old Funds no longer in use';
    }

    public function up(Schema $schema): void
    {
        // creating temporary table to work around MySQL limitation that we can't update the same table that we're
        // selecting from in a query. The temp table will automatically be garbaged when the connection ends.

        $this->addSql(<<<SQL
           CREATE TEMPORARY TABLE new_Fund SELECT * FROM Fund;
            DELETE FROM Fund WHERE id IN
            (
                SELECT new_Fund.id FROM new_Fund
                LEFT OUTER JOIN CampaignFunding ON CampaignFunding.fund_id = new_Fund.id
                HAVING COUNT(CampaignFunding.id) = 0
            )
            ORDER BY new_Fund.id LIMIT 27
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
