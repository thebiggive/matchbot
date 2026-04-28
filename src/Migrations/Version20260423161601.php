<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423161601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CampaignStatistics approxStatus column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics ADD approxStatus VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics DROP approxStatus');
    }
}
