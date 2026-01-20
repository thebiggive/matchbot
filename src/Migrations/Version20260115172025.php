<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20260115172025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Campaign.summary for easier searching';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Staging && Environment::current() !== Environment::Regression) {
            // this line already ran in those environments so would fail if we tried to run it again.
            $this->addSql('ALTER TABLE Campaign ADD summary VARCHAR(255) NOT NULL');
        }

        // actual campaign summaries can be over 3,000 characters long so allow a slight buffer to 5_000
        $this->addSql('ALTER TABLE Campaign modify summary VARCHAR(5000) NOT NULL');

        $this->addSql('UPDATE Campaign SET summary = salesforceData->>"$.summary"');

        $this->addSql(<<<SQL
            ALTER TABLE Campaign ADD searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ', 
                    name, 
                    summary, 
                    -- We extract the arrays as strings. 
                    -- MySQL flattens ["A", "B"] into "[\"A\", \"B\"]", 
                    -- and FULLTEXT treats the brackets/quotes as word separators.
                    salesforceData->>"$.beneficiaries",
                    salesforceData->>"$.categories",
                    salesforceData->>"$.countries"
                )
            ) STORED
        SQL);

        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign (searchable_text)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP summary');
        $this->addSql('ALTER TABLE Campaign DROP searchable_text');
        $this->addSql('DROP INDEX FULLTEXT_GLOBAL_SEARCH ON Campaign');
    }
}
