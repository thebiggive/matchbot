<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250522110906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Donation.payoutSuccessful field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD payoutSuccessful TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP payoutSuccessful');
    }
}
