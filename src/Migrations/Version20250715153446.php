<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-413 – Add campaign stats lastCheck and lastRealUpdate fields.
 */
final class Version20250715153446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add campaign stats lastCheck and lastRealUpdate fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE CampaignStatistics
                ADD lastCheck DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD lastRealUpdate DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics DROP lastCheck, DROP lastRealUpdate');
    }
}
