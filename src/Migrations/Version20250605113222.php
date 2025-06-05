<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250605113222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set new metacampaign field on all campaigns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Campaign set metaCampaignSlug = salesforceData->'$.parentRef'
            -- no limit intended, should be quick enough to do ~10k campaigns at once.
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        {
            $this->addSql(<<<'SQL'
            UPDATE Campaign set metaCampaignSlug = null
            -- no limit intended
            SQL
            );
        }

    }
}
