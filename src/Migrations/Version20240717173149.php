<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-368 – move some `CampaignFunding`s to match fund reallocation in Salesforce
 */
final class Version20240717173149 extends AbstractMigration
{
    const POSTCODE_GREEN_TRUST_FUND_ID = 24089;

    public function getDescription(): string
    {
        return 'Move some CampaignFundings to match Salesforce';
    }

    public function up(Schema $schema): void
    {
        $campaignsToUpdate = [
            'a056900002SEW18AAH' => 24081, // Fund ID for Reed
            'a056900002SETkhAAH' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SEVhXAAX' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SEVkqAAH' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SEVoiAAH' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SEVpMAAX' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SEVvtAAH' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SEW8YAAX' => self::POSTCODE_GREEN_TRUST_FUND_ID,
            'a056900002SMAFHAA5' => self::POSTCODE_GREEN_TRUST_FUND_ID,
        ];

        foreach ($campaignsToUpdate as $campaignSFID => $newFundNumericId) {
            $this->addSql(<<<SQL
                UPDATE CampaignFunding
                INNER JOIN `Campaign_CampaignFunding` ON `Campaign_CampaignFunding`.`campaignfunding_id` = `CampaignFunding`.`id`
                INNER JOIN `Campaign` ON `Campaign`.`id` = `Campaign_CampaignFunding`.`campaign_id`
                SET CampaignFunding.fund_id = $newFundNumericId
                WHERE Campaign.salesforceId = '$campaignSFID'
            SQL
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
