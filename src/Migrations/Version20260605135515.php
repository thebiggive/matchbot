<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SO-78 Populate CampaignLocations with countries from Campaign salesforceData
 */
final class Version20260605135515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Populate CampaignLocations with countries from Campaign salesforceData';
    }

    public function up(Schema $schema): void
    {
        // Looks like MySQL 8+ default collation to utf8mb4_0900_ai_ci which means CampaignLocation
        // has that in all environments, since it is new and we didn't specify another.
        $this->addSql(<<<EOT
            INSERT INTO CampaignLocation (campaign_id, countryName, regionCode)
            SELECT c.id, jt.countryName, NULL
            FROM Campaign c
            JOIN JSON_TABLE(c.salesforceData, '$.countries[*]' COLUMNS (countryName VARCHAR(100) PATH '$')) jt
            WHERE jt.countryName IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 
                  FROM CampaignLocation cl 
                  WHERE cl.campaign_id = c.id 
                    AND cl.countryName = jt.countryName COLLATE utf8mb4_0900_ai_ci
                    AND (cl.regionCode IS NULL OR cl.regionCode = '')
              );
        EOT);
    }

    public function down(Schema $schema): void
    {
        // No safe down() migration because we cannot distinguish between CampaignLocations
        // added by this migration and those that existed previously with NULL regionCode.
    }
}
