<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove `salesforceLastPull` from push-only Donation object
 */
final class Version20191029162114 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Remove `salesforceLastPull` from push-only Donation object';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation DROP salesforceLastPull');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation ADD salesforceLastPull DATETIME DEFAULT NULL');
    }
}
