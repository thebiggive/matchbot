<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove buggy default tip value
 */
final class Version20191126051351 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Remove buggy default tip value';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation CHANGE tipAmount tipAmount NUMERIC(18, 2) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation CHANGE tipAmount tipAmount NUMERIC(18, 2) DEFAULT \'0.00\' NOT NULL');
    }
}
