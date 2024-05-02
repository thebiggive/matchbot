<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240502101845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match fund corrections for MAT-361 campaigns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            -- reduce amount from 5_000 to 2_500, reduce amountAvailable from 1_890 to -610
            UPDATE CampaignFunding SET amount = amount - 2500, amountAvailable = amountAvailable - 2500 WHERE id = 29101 LIMIT 1;

            -- reduce amount from 2_500 to zero;
            UPDATE FundingWithdrawal set amount = amount - 2500 where id = 717813;
            
            -- reduce amount from 20_000 to 10_000, reduce amountAvailable from 9952 to -48
            UPDATE CampaignFunding SET amount = amount - 10000, amountAvailable = amountAvailable - 10000 WHERE id = 29116 LIMIT 1;

            -- reduce amount from 10_000 to zero
            UPDATE FundingWithdrawal set amount = amount - 10000 where id = 709467 LIMIT 1;
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

    }
}
