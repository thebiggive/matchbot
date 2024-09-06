<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-189 - Add new Charity fields for in-house Gift Aid support, with `tbgClaimingGiftAid` temporarily nullable; then populate that field for existing charities.
 */
final class Version20211029163214 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add new Charity fields for in-house Gift Aid support, with `tbgClaimingGiftAid` temporarily nullable';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Charity ADD hmrcReferenceNumber VARCHAR(7) DEFAULT NULL, ADD tbgClaimingGiftAid TINYINT(1) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4CC08E829EF7853B ON Charity (hmrcReferenceNumber)');

        // Set a non-null value for existing charities, without adding a schema `DEFAULT`.
        $this->addSql('UPDATE Charity SET tbgClaimingGiftAid = 0 WHERE tbgClaimingGiftAid IS NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX UNIQ_4CC08E829EF7853B ON Charity');
        $this->addSql('ALTER TABLE Charity DROP hmrcReferenceNumber, DROP tbgClaimingGiftAid');
    }
}
