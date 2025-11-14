<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * We need money to be able to support more than £21M (or 21M of any major unit), which means we need more pence than
 * fits in a 32 bit int (2,147,483,647) so changing from int to big int mysql type. Doctrine will make this a string
 * when loading money from the DB, and it should then get cast back to int in PHP but that's fine because our PHP
 * uses 64 bit ints.
 */
final class Version20251114105436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change all money amounts from int to bigint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE total_funding_allocation_amountInPence total_funding_allocation_amountInPence BIGINT NOT NULL, CHANGE amount_pledged_amountInPence amount_pledged_amountInPence BIGINT NOT NULL, CHANGE total_fundraising_target_amountInPence total_fundraising_target_amountInPence BIGINT NOT NULL');
        $this->addSql('ALTER TABLE CampaignStatistics CHANGE amount_raised_amountInPence amount_raised_amountInPence BIGINT NOT NULL, CHANGE match_funds_used_amountInPence match_funds_used_amountInPence BIGINT NOT NULL, CHANGE donation_sum_amountInPence donation_sum_amountInPence BIGINT NOT NULL, CHANGE match_funds_total_amountInPence match_funds_total_amountInPence BIGINT NOT NULL, CHANGE match_funds_remaining_amountInPence match_funds_remaining_amountInPence BIGINT NOT NULL, CHANGE distance_to_target_amountInPence distance_to_target_amountInPence BIGINT NOT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE expectedMatchAmount_amountInPence expectedMatchAmount_amountInPence BIGINT NOT NULL');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE total_adjustment_amountInPence total_adjustment_amountInPence BIGINT NOT NULL, CHANGE imf_campaign_target_override_amountInPence imf_campaign_target_override_amountInPence BIGINT NOT NULL, CHANGE match_funds_total_amountInPence match_funds_total_amountInPence BIGINT NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE donationAmount_amountInPence donationAmount_amountInPence BIGINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE total_funding_allocation_amountInPence total_funding_allocation_amountInPence INT NOT NULL, CHANGE amount_pledged_amountInPence amount_pledged_amountInPence INT NOT NULL, CHANGE total_fundraising_target_amountInPence total_fundraising_target_amountInPence INT NOT NULL');
        $this->addSql('ALTER TABLE CampaignStatistics CHANGE amount_raised_amountInPence amount_raised_amountInPence INT NOT NULL, CHANGE donation_sum_amountInPence donation_sum_amountInPence INT NOT NULL, CHANGE match_funds_total_amountInPence match_funds_total_amountInPence INT NOT NULL, CHANGE match_funds_used_amountInPence match_funds_used_amountInPence INT NOT NULL, CHANGE match_funds_remaining_amountInPence match_funds_remaining_amountInPence INT NOT NULL, CHANGE distance_to_target_amountInPence distance_to_target_amountInPence INT NOT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE expectedMatchAmount_amountInPence expectedMatchAmount_amountInPence INT NOT NULL');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE total_adjustment_amountInPence total_adjustment_amountInPence INT NOT NULL, CHANGE imf_campaign_target_override_amountInPence imf_campaign_target_override_amountInPence INT NOT NULL, CHANGE match_funds_total_amountInPence match_funds_total_amountInPence INT NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE donationAmount_amountInPence donationAmount_amountInPence INT NOT NULL');
    }
}
