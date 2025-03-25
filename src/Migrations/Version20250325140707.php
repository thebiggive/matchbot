<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-410 – Populate new field; remove old allocation order from CampaignFunding
 */
final class Version20250325140707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Populate new field; remove old allocation order from CampaignFunding';
    }

    public function up(Schema $schema): void
    {
        // Patch new Fund field with previous linked CampaignFundings' allocation orders.
        $this->addSql(<<<SQL
            UPDATE Fund f
            JOIN CampaignFunding cf ON cf.fund_id = f.id
            SET f.allocationOrder = cf.allocationOrder
            WHERE f.allocationOrder IS NULL AND cf.allocationOrder IS NOT NULL
        SQL);

        $this->addSql('ALTER TABLE CampaignFunding DROP allocationOrder');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignFunding ADD allocationOrder INT NOT NULL');
    }
}
