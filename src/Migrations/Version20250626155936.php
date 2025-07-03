<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250626155936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make MetaCampaign.status nullable to reflect reality of data currently served from SF (at least in staging env)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE status status VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE status status VARCHAR(255) NOT NULL');
    }
}
