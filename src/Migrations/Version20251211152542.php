<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20251211152542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manually add retrospective match fund to a campaign to as correction due to issue MAT-472';
    }

    public function up(Schema $schema): void
    {
        if (! Environment::current()->isProduction()) {
            // we can't add match funds for a donation that exists in prod in any other environment.
            return;
        }

        // it looks like the combination of retrospective matching and match funds redistribtuion running at the same
        // time after CC close on Tuesday meant that although the correct total of match funds was allocated for this
        // campaign £4450 ended upon the wrong donation, leaving one donation matched at £4450 over 100% and another
        // (# 1361196 ) not fully matched and big enough to be used to match that amount.
        //
        // The fix pushed yesterday in Version20251210114851 corrected the over-matched donation but that seems to have
        // left the campaign as a whole short of £4450 in match funds that it is entitled. This code adds the match funds
        // usage required.
        $this->addSql(<<<SQL
            INSERT INTO FundingWithdrawal (donation_id, amount, createdAt, updatedAt, campaignFunding_id) 
            VALUES (1361196, 4450, now(), now(), 40616)
        SQL);

        // re-send this donation to Salesforce to allow SF to caluculate a new total match funding usage.
        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'pending-update' WHERE id = 1361196");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM FundingWithdrawal WHERE donation_id = 1361196 and amount = 4450 LIMIT 1;
        SQL);
    }
}
