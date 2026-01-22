<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260119161807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add full text search index to charity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Charity ADD COLUMN searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    name,
                    regulatorNumber,
                    websiteUri
                )
            ) STORED
        SQL);

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Charity (searchable_text)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP COLUMN searchable_text');
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Charity');
    }
}

