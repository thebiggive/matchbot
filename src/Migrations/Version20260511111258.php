<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511111258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused MetaCampaign.masterCampaignStatus';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP masterCampaignStatus');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD masterCampaignStatus VARCHAR(255) DEFAULT NULL');
    }
}
