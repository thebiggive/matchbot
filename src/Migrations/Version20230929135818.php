<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-318 – add Doctrine type metadata to now-immutable `Donation`.`collectedAt`.
 */
final class Version20230929135818 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Make Donation.collectedAt immutable';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE collectedAt collectedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE collectedAt collectedAt DATETIME DEFAULT NULL');
    }
}
