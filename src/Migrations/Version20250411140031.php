<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250411140031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add column Donation.donorUUID ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD donorUUID CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP donorUUID');
    }
}
