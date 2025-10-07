<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-458 Remove an extraneous CC25 CampaignFunding
 */
final class Version20251007120331 extends AbstractMigration
{
    private int $campaignFundingId = 47517;

    public function getDescription(): string
    {
        return 'Remove an extraneous CC25 CampaignFunding';
    }

    public function up(Schema $schema): void
    {

        $this->addSql("DELETE FROM Campaign_CampaignFunding WHERE campaignfunding_id = {$this->campaignFundingId} AND campaign_id = 30231 LIMIT 1");
        $this->addSql("DELETE FROM CampaignFunding WHERE id = {$this->campaignFundingId} LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
