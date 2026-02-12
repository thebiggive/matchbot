<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove metacampaign fields that are now calculated in MatchBot
 */
final class Version20260211115954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BG2-3027 Remove metacampaign fields that are now calculated in MatchBot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP imf_campaign_target_override_amountInPence, DROP imf_campaign_target_override_currency, DROP match_funds_total_amountInPence, DROP match_funds_total_currency');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD imf_campaign_target_override_amountInPence BIGINT NOT NULL, ADD imf_campaign_target_override_currency VARCHAR(3) NOT NULL, ADD match_funds_total_amountInPence BIGINT NOT NULL, ADD match_funds_total_currency VARCHAR(3) NOT NULL');
    }
}
