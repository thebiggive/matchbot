<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629102112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update approx status to active on one campaign for staging testing - should be done automatically but our automation does not anticipate changes in this direction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sql: "update CampaignStatistics set approxStatus = 'Active' where campaignSalesforceId = 'a05WS00000BpXI9YAN' limit 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sql: "update CampaignStatistics set approxStatus = 'Expired' where campaignSalesforceId = 'a05WS00000BpXI9YAN' limit 1");
    }
}
