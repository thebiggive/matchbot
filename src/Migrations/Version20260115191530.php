<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115191530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize Campaign.searchable_text generated column JSON path quoting to match ORM mapping';
    }

    public function up(Schema $schema): void
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

    public function down(Schema $schema): void
    {
        // Revert to the previous definition that used double quotes inside JSON paths
        $this->addSql(<<<SQL
            ALTER TABLE Campaign CHANGE searchable_text searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ', 
                    name,
                    summary,
                    salesforceData->>"$.beneficiaries",
                    salesforceData->>"$.categories",
                    salesforceData->>"$.countries"
                )
            ) STORED
        SQL);
    }
}
