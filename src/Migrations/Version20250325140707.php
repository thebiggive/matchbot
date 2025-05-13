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
    #[\Override]
    public function getDescription(): string
    {
        return 'Populate new field; remove old allocation order from CampaignFunding';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // Patch new Fund field with previous linked CampaignFundings' allocation orders.
        $this->addSql(<<<'SQL'
            UPDATE Fund SET allocationOrder = CASE
                WHEN fundType = 'pledge' THEN 100
                WHEN fundType = 'championFund' THEN 200
                WHEN fundType = 'topupPledge' THEN 300
            END
        SQL);

        $this->addSql('ALTER TABLE CampaignFunding DROP allocationOrder');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignFunding ADD allocationOrder INT NOT NULL');
    }
}
