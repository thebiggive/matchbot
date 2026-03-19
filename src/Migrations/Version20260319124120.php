<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319124120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column CampaignFunding.adjustmentLog - for now used only for manual viewing not automated in business logic';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignFunding ADD adjustmentLog JSON DEFAULT NULL');
        $this->addSql('UPDATE CampaignFunding SET adjustmentLog = \'[]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignFunding DROP adjustmentLog');
    }
}
