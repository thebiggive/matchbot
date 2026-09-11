<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DON-1207 Drop and replace the 4 ngram search indexes (ii)
 */
final class Version20260911153909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop and replace the 4 ngram search indexes (ii)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Campaign');
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Charity');
        $this->addSql('DROP INDEX FULLTEXT_NORMALISED_NAME ON Charity');

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Campaign (normalisedName) WITH PARSER ngram');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign (searchable_text) WITH PARSER ngram');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NORMALISED_NAME ON Charity (normalisedName) WITH PARSER ngram');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Charity (searchable_text) WITH PARSER ngram');
    }

    public function down(Schema $schema): void
    {
        // No un-rebuild.
    }
}
