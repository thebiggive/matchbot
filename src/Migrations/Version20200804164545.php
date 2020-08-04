<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add new Donation fields
 */
final class Version20200804164545 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add new Donation fields';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation ADD donorHomeAddressLine1 VARCHAR(255) DEFAULT NULL, ADD donorHomePostcode VARCHAR(255) DEFAULT NULL, ADD tipGiftAid TINYINT(1) DEFAULT NULL');
        $this->addSql('UPDATE Donation SET tipGiftAid = giftAid WHERE tipGiftAid IS NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation DROP donorHomeAddressLine1, DROP donorHomePostcode, DROP tipGiftAid');
    }
}
