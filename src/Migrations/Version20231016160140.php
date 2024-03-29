<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231016160140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timestamps to DonorAccount talbe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD createdAt DATETIME NOT NULL, ADD updatedAt DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount DROP createdAt, DROP updatedAt');
    }
}
