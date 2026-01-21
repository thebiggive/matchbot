<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20260121155900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use normalisedNames for searchable text indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Campaign MODIFY searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    normalisedName,
                    summary,
                    json_unquote(json_extract(salesforceData, '$.beneficiaries')),
                    json_unquote(json_extract(salesforceData, '$.categories')),
                    json_unquote(json_extract(salesforceData, '$.countries'))
                )
            ) STORED AFTER normalisedName
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE Charity MODIFY COLUMN searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    normalisedName,
                    regulatorNumber,
                    websiteUri
                )
            ) STORED AFTER normalisedName
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Campaign MODIFY searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    name,
                    summary,
                    json_unquote(json_extract(salesforceData, '$.beneficiaries')),
                    json_unquote(json_extract(salesforceData, '$.categories')),
                    json_unquote(json_extract(salesforceData, '$.countries'))
                )
            ) STORED AFTER normalisedName
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE Charity MODIFY COLUMN searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    name,
                    regulatorNumber,
                    websiteUri
                )
            ) STORED
        SQL);
    }
}
