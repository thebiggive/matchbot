<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make Donation fields that are now optional on create nullable
 */
final class Version20200804150108 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Make Donation fields that are now optional on create nullable';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation CHANGE charityComms charityComms TINYINT(1) DEFAULT NULL, CHANGE giftAid giftAid TINYINT(1) DEFAULT NULL, CHANGE tbgComms tbgComms TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation CHANGE charityComms charityComms TINYINT(1) NOT NULL, CHANGE giftAid giftAid TINYINT(1) NOT NULL, CHANGE tbgComms tbgComms TINYINT(1) NOT NULL');
    }
}
