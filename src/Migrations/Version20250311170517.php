<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250311170517 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'ADD Charity.phoneNumber column';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // next line was auto-generated, it doesn't really matter what if any default we have.
        $this->addSql('ALTER TABLE Campaign CHANGE salesforceData salesforceData JSON NOT NULL');

        $this->addSql('ALTER TABLE Charity ADD phoneNumber VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE salesforceData salesforceData JSON DEFAULT \'_utf8mb4\\\\\'\'{}\\\\\'\'\' NOT NULL');
        $this->addSql('ALTER TABLE Charity DROP phoneNumber');
    }
}
