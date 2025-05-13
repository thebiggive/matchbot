<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240502101845 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Match fund corrections for MAT-361 campaigns';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            -- reduce amount from 5_000 to 2_500, reduce amountAvailable from 1_890 to 0
            UPDATE CampaignFunding SET amount = amount - 2500, amountAvailable = amountAvailable - 1890 WHERE id = 29101 LIMIT 1;

            -- reduce amount from 2_500 to 1890;
            UPDATE FundingWithdrawal set amount = amount - 610 where id = 717813 LIMIT 1;
            
            -- reduce amount from 20_000 to 10_000, reduce amountAvailable from 9952 to 0
            UPDATE CampaignFunding SET amount = amount - 10000, amountAvailable = amountAvailable - 9952 WHERE id = 29116 LIMIT 1;

            -- reduce amount from 10_000 to 9952
            UPDATE FundingWithdrawal set amount = amount - 48 where id = 709467 LIMIT 1;

            -- no need to update Fund objects becase the fund amount is not actually used for anything in Matchbot.
            
            
            UPDATE Donation SET salesforcePushStatus = 'pending-update'
            WHERE Donation.salesforceId in ('a0669000020LhfRAAS', 'a0669000020LWfpAAG') LIMIT 2;
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE CampaignFunding SET amount = amount + 2500, amountAvailable = amountAvailable + 1890 WHERE id = 29101 LIMIT 1;

            UPDATE FundingWithdrawal set amount = amount + 610 where id = 717813 LIMIT 1;
            
            UPDATE CampaignFunding SET amount = amount + 10000, amountAvailable = amountAvailable + 9952 WHERE id = 29116 LIMIT 1;

            UPDATE FundingWithdrawal set amount = amount + 48 where id = 709467 LIMIT 1;
            SQL);
    }
}
