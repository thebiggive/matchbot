<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * These statistics are outdated thanks to {@see Version20251211152542 }. If we delete them they will
 * be auto-regenerated in a minute by {@see UpdateCampaignDonationStats}. For up to a minute while we wait for that
 * the front end campaign page will show zero raised.
 *
 * If the campaign was open the stats would be auto re-calculated after the next donation, but as it is not we need
 * to do something to force recalculation for display on the closed campaign page.
 */
final class Version20251211165617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete outdated campaign stats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE from CampaignStatistics where CampaignStatistics.campaign_id = 29750 LIMIT 1;
        SQL);
    }

    public function down(Schema $schema): void
    {
        // no un-patch.
    }
}
