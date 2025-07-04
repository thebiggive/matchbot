<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250704161340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timestamps to meta campaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD createdAt DATETIME NOT NULL, ADD updatedAt DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP createdAt, DROP updatedAt');
    }
}
