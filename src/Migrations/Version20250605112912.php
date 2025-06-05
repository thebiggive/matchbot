<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250605112912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Campaign.metaCampaignSlug';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD metaCampaignSlug VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX metaCampaignSlug ON Campaign (metaCampaignSlug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX metaCampaignSlug ON Campaign');
        $this->addSql('ALTER TABLE Campaign DROP metaCampaignSlug');
    }
}
