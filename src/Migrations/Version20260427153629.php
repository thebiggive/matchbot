<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427153629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set all campaign stats approx statuses to non-empty to stop enum doctrine errors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sql: "UPDATE CampaignStatistics SET approxStatus = 'Preview' where approxStatus = ''");
    }

    public function down(Schema $schema): void
    {
        // no going back.
    }
}
