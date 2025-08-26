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
        $this->addSql(<<<SQL
            DELETE FROM Fund WHERE id IN
            (
                SELECT Fund.id FROM Fund
                LEFT OUTER JOIN CampaignFunding ON CampaignFunding.fund_id = Fund.id
                HAVING COUNT(CampaignFunding.id) = 0
            )
            ORDER BY Fund.id LIMIT 27
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
