<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250307145925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SalesforceData column to Charity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD salesforceData JSON NOT NULL');
        $this->addSql('UPDATE Charity SET salesforceData = \'{}\' where ID > 0');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP salesforceData');
    }
}
