<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20260121143947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add generated Charity.normalisedName column for use in search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Charity ADD normalisedName varchar(255) GENERATED ALWAYS AS (
                REGEXP_REPLACE(name, '', '')) STORED
        SQL);

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Charity (normalisedName)');

        $this->addSql(<<<SQL
            ALTER TABLE Campaign ADD normalisedName varchar(255) GENERATED ALWAYS AS (
                REGEXP_REPLACE(name, '[\'`‘’]+', '')) STORED
        SQL);

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Campaign (normalisedName)');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP COLUMN normalisedName');
        $this->addSql('ALTER TABLE Campaign DROP COLUMN normalisedName');

        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Charity');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Campaign');
    }
}
