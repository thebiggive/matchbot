<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240819164301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column matchbot.Donation.totalPaidByDonor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD totalPaidByDonor NUMERIC(18, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP totalPaidByDonor');
    }
}
