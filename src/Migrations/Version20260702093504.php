<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-3326 Move fund withdrawals connected to one SCW26 CampaignFunding + related amount updates.
 */
final class Version20260702093504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move donations connected to one SCW26 CampaignFunding';
    }

    public function up(Schema $schema): void
    {
        // Flip ID for all FundingWithdrawals, including released ones. 41 rows expected based on a simulated Prod query.
        $this->addSql('UPDATE FundingWithdrawal SET campaignFunding_id = 51331 WHERE campaignFunding_id = 50838 LIMIT 41');
        // Marks the old CampaignFunding as eligible for a systematic update to amount £0, which it already is in
        // SF as this was done earlier than normal.
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = amount WHERE id = 50838 LIMIT 1');
        // New fund should be entirely spent, as the old one was, so set `amountAvailable` to £0 accordingly.
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = 0 WHERE id = 51331 LIMIT 1');
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
