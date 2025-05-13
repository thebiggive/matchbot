<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Donation.tipAmount
 */
final class Version20191119154404 extends AbstractMigration
{
    #[\Override]
    public function getDescription() : string
    {
        return 'Add Donation.tipAmount';
    }

    #[\Override]
    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql("ALTER TABLE Donation ADD tipAmount NUMERIC(18, 2) DEFAULT '0.00' NOT NULL");
    }

    #[\Override]
    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation DROP tipAmount');
    }
}
