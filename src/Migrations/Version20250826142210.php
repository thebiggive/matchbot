<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2907 Delete prematurely created duplicate CampaignFundings.
 */
final class Version20250826142210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BG2-2907 Delete prematurely created duplicate CampaignFundings';
    }

    public function up(Schema $schema): void
    {
        // Ran query in Production manually to reduce complexity in migration.
        /**
         * SELECT DISTINCT Campaign_CampaignFunding.campaignfunding_id
         * FROM Campaign_CampaignFunding INNER JOIN CampaignFunding ON Campaign_CampaignFunding.campaignfunding_id = CampaignFunding.id
         * WHERE CampaignFunding.createdAt >= '2025-08-22' AND Campaign_CampaignFunding.campaign_id IN (
         * SELECT Campaign.id from Campaign JOIN Charity on Campaign.charity_id = Charity.id
         * WHERE Campaign.metaCampaignSlug = 'small-charity-week-2025'
         * );
         */
        $rogueCampaignFundingIds = [
            44596,
            44597,
            44598,
            44599,
            44600,
            44601,
            44602,
            44603,
            44604,
            44605,
            44606,
            44607,
            44608,
            44609,
            44576,
            44610,
            44611,
            44612,
            44613,
            44614,
            44615,
            44616,
            44617,
            44618,
            44619,
            44620,
            44621,
        ];

        $this->addSql(
            'DELETE FROM Campaign_CampaignFunding WHERE campaignfunding_id IN (:ids) LIMIT 27',
            ['ids' => $rogueCampaignFundingIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $this->addSql(
            'DELETE FROM CampaignFunding WHERE id IN (:ids) LIMIT 27',
            ['ids' => $rogueCampaignFundingIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
