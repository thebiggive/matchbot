<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115191045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update Campaign.searchable_text generated column to include summary in CONCAT_WS()';
    }

    public function up(Schema $schema): void
    {
        // Align the generated column with the entity mapping (includes summary)
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

    public function down(Schema $schema): void
    {
        // Revert to the previous definition (without summary in the generated expression)
        $this->addSql(<<<SQL
            ALTER TABLE Campaign CHANGE searchable_text searchable_text TEXT GENERATED ALWAYS AS (
                CONCAT_WS(' ', 
                    name,
                    salesforceData->>"$.beneficiaries",
                    salesforceData->>"$.categories",
                    salesforceData->>"$.countries"
                )
            ) STORED
        SQL);
    }
}
