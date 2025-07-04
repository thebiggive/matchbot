<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-413 – Add CampaignStatistics table for summaries / search ordering.
 */
final class Version20250625105610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CampaignStatistics table for summaries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE CampaignStatistics
(
    campaign_id                    INT UNSIGNED NOT NULL,
    campaignSalesforceId           VARCHAR(18)  NOT NULL,
    createdAt                      DATETIME     NOT NULL,
    updatedAt                      DATETIME     NOT NULL,
    amount_raised_amountInPence    INT          NOT NULL,
    amount_raised_currency         VARCHAR(3)   NOT NULL,
    match_funds_used_amountInPence INT          NOT NULL,
    match_funds_used_currency      VARCHAR(3)   NOT NULL,
    UNIQUE INDEX UNIQ_7DDC8DA446D048DD (campaignSalesforceId),
    INDEX amount_raised_amountInPence (amount_raised_amountInPence),
    INDEX match_funds_used_amountInPence (match_funds_used_amountInPence),
    PRIMARY KEY (campaign_id)
) DEFAULT CHARACTER SET utf8mb4
  COLLATE `utf8mb4_unicode_ci`
  ENGINE = InnoDB');
        $this->addSql('ALTER TABLE CampaignStatistics ADD CONSTRAINT FK_7DDC8DA4F639F774 FOREIGN KEY (campaign_id) REFERENCES Campaign (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics DROP FOREIGN KEY FK_7DDC8DA4F639F774');
        $this->addSql('DROP TABLE CampaignStatistics');
    }
}
