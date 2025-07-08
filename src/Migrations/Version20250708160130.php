<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250708160130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove spurious quotation marks from metacampaign slugs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Campaign SET metaCampaignSlug = REPLACE(metaCampaignSlug, '"', '') WHERE metaCampaignSlug is not null;
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('no going back');
    }
}
