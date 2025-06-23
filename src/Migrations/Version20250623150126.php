<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250623150126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fields to MetaCampaign as required to port Campaign_Target__c implementation from SF into PHP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE MetaCampaign
            ADD imf_campaign_target_override_amountInPence  INT        NOT NULL,
            ADD imf_campaign_target_override_currency       VARCHAR(3) NOT NULL,
            ADD total_funding_allocation_amountInPence      INT        NOT NULL,
            ADD total_funding_allocation_currency           VARCHAR(3) NOT NULL,
            ADD amount_pledged_amountInPence                INT        NOT NULL,
            ADD amount_pledged_currency                     VARCHAR(3) NOT NULL,
            ADD total_matched_funds_available_amountInPence INT        NOT NULL,
            ADD total_matched_funds_available_currency      VARCHAR(3) NOT NULL
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE MetaCampaign
            DROP imf_campaign_target_override_amountInPence,
            DROP imf_campaign_target_override_currency,
            DROP total_funding_allocation_amountInPence,
            DROP total_funding_allocation_currency,
            DROP amount_pledged_amountInPence,
            DROP amount_pledged_currency,
            DROP total_matched_funds_available_amountInPence,
            DROP total_matched_funds_available_currency
        SQL
        );
    }
}
