<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424142601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index CampaignStatistics.approxStatus';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX approxStatus ON CampaignStatistics (approxStatus)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX approxStatus ON CampaignStatistics');
    }
}
