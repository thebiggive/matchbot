<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-413 – Add donation sum & match funds total columns to CampaignStatistics.
 */
final class Version20250704105819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add donation sum & match funds total columns to CampaignStatistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE CampaignStatistics
                ADD donation_sum_amountInPence INT NOT NULL,
                ADD donation_sum_currency VARCHAR(3) NOT NULL,
                ADD match_funds_total_amountInPence INT NOT NULL,
                ADD match_funds_total_currency VARCHAR(3) NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE CampaignStatistics
                DROP donation_sum_amountInPence,
                DROP donation_sum_currency,
                DROP match_funds_total_amountInPence,
                DROP match_funds_total_currency
        SQL);
    }
}
