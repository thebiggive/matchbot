<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make MetaCampaign.summary long enough for existing records.
 */
final class Version20250709090013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make MetaCampaign.summary long enough for existing records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE summary summary VARCHAR(1000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE summary summary VARCHAR(255) DEFAULT NULL');
    }
}
