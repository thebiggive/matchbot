<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DON-1207 Improve search indexes
 */
final class Version20260911091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Improve search indexes';
    }

    public function up(Schema $schema): void
    {
        // These 2 unused for search, delete entirely.
        $this->addSql('DROP INDEX FULLTEXT_NAME ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_NAME ON Charity');

        // Doctrine-managed simple / non-fulltext replacements for exact match queries.
        $this->addSql('CREATE INDEX name ON Campaign (name)');
        $this->addSql('CREATE INDEX name ON Charity (name)');

        // Replace other 4 fulltext indexes with ngram versions.
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Charity');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Charity');

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Campaign (normalisedName) WITH PARSER ngram');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign (searchable_text) WITH PARSER ngram');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Charity (normalisedName) WITH PARSER ngram');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Charity (searchable_text) WITH PARSER ngram');

        $this->addSql('OPTIMIZE TABLE Campaign'); // Will recreate + analyze as InnoDB
        $this->addSql('OPTIMIZE TABLE Charity');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NAME ON Charity (name)');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NAME ON Campaign (name)');

        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Charity');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Charity');

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Campaign (normalisedName)');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign (searchable_text)');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Charity (normalisedName)');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Charity (searchable_text)');

        $this->addSql('DROP INDEX name ON Campaign');
        $this->addSql('DROP INDEX name ON Charity');

        $this->addSql('OPTIMIZE TABLE Campaign');
        $this->addSql('OPTIMIZE TABLE Charity');
    }
}
