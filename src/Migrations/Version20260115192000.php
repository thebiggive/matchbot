<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115192000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align Campaign.searchable_text generated column with ORM mapping (use json_unquote(json_extract(...)))';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Campaign CHANGE searchable_text searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    name,
                    summary,
                    json_unquote(json_extract(salesforceData, '$.beneficiaries')),
                    json_unquote(json_extract(salesforceData, '$.categories')),
                    json_unquote(json_extract(salesforceData, '$.countries'))
                )
            ) STORED
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Campaign CHANGE searchable_text searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ',
                    name,
                    summary,
                    salesforceData->>'$.beneficiaries',
                    salesforceData->>'$.categories',
                    salesforceData->>'$.countries'
                )
            ) STORED
        SQL);
    }
}
