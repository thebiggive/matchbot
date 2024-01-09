<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230622095211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Donation.refundedAt field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD refundedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\' AFTER collectedAt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP refundedAt');
    }
}
