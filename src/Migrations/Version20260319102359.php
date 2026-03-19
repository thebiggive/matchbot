<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exact copy of Version20260318113924 from yesterday - running again in prod should mitigate issues with live
 * campaign while we work on a more general fix.
 */
final class Version20260319102359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct CampaignFunding records that don\'t correctly reflect sum of amounts withdrawn';
    }

    public function up(Schema $schema): void
    {
        $afi2026Ids = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT CampaignFunding.id from CampaignFunding
            JOIN Campaign_CampaignFunding on Campaign_CampaignFunding.campaignfunding_id = CampaignFunding.id
            JOIN Campaign on Campaign_CampaignFunding.campaign_id = Campaign.id
            WHERE Campaign.metaCampaignSlug = 'arts-for-impact-2026';
        SQL
        );

        $afi2026Ids[] = '462453572345'; // fake ID just to avoid empty list which isn't accepted in sql syntax

        $idsString = \implode(', ', $afi2026Ids);

        $this->addSql(<<<SQL
                UPDATE CampaignFunding 
                LEFT JOIN (SELECT FundingWithdrawal.campaignFunding_id,
                                          COALESCE(SUM(FundingWithdrawal.amount), 0) AS withdrawn_total
                                  FROM FundingWithdrawal
                                  WHERE FundingWithdrawal.releasedAt is null
                                  GROUP BY FundingWithdrawal.campaignFunding_id) AS FundingWithdrawalTotals
                      ON FundingWithdrawalTotals.campaignFunding_id = CampaignFunding.id
                SET CampaignFunding.amountAvailable = GREATEST(0, CampaignFunding.amount - COALESCE(FundingWithdrawalTotals.withdrawn_total, 0))
        WHERE CampaignFunding.id IN ($idsString)
        SQL
        );
    }

    public function down(Schema $schema): never
    {
        $this->throwIrreversibleMigrationException('Cannot restore previous values');
        throw new \Error("Previous line always throws but isn't marked as returning never");
    }
}
