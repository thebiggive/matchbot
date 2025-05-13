<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230622095211 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add Donation.refundedAt field';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD refundedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\' AFTER collectedAt');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP refundedAt');
    }
}
