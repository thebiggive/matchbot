<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-413 – Index CampaignStatistics.lastCheck.
 */
final class Version20250716102844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index CampaignStatistics.lastCheck';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX lastCheck ON CampaignStatistics (lastCheck)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX lastCheck ON CampaignStatistics');
    }
}
