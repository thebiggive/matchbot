<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701140354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new field Donation.fundsReservedUntil';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD fundsReservedUntil DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP fundsReservedUntil');
    }
}
