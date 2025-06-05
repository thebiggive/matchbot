<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250605112912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Campaign.metaCampaignSfId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD metaCampaignSfId VARCHAR(18) DEFAULT NULL');
        $this->addSql('CREATE INDEX metaCampaignSfId ON Campaign (metaCampaignSfId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX metaCampaignSfId ON Campaign');
        $this->addSql('ALTER TABLE Campaign DROP metaCampaignSfId');
    }
}
