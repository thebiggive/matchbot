<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250605141453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for MetaCampaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE slug slug VARCHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C36155EC989D9B62 ON MetaCampaign (slug)');
        $this->addSql('CREATE INDEX slug ON MetaCampaign (slug)');
        $this->addSql('CREATE INDEX title ON MetaCampaign (title)');
        $this->addSql('CREATE INDEX status ON MetaCampaign (status)');
        $this->addSql('CREATE INDEX hidden ON MetaCampaign (hidden)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_C36155EC989D9B62 ON MetaCampaign');
        $this->addSql('DROP INDEX slug ON MetaCampaign');
        $this->addSql('DROP INDEX title ON MetaCampaign');
        $this->addSql('DROP INDEX status ON MetaCampaign');
        $this->addSql('DROP INDEX hidden ON MetaCampaign');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE slug slug VARCHAR(255) NOT NULL');
    }
}
