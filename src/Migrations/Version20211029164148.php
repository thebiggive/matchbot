<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-189 – Make tbgClaimingGiftAid non-nullable, now it has been populated for existing records.
 */
final class Version20211029164148 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Make tbgClaimingGiftAid non-nullable, now it has been populated for existing records';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Charity CHANGE tbgClaimingGiftAid tbgClaimingGiftAid TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Charity CHANGE tbgClaimingGiftAid tbgClaimingGiftAid TINYINT(1) DEFAULT NULL');
    }
}
