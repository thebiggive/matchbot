<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add CampaignStatistics.distance_to_target; fill in new field currencies.
 */
final class Version20250708110227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CampaignStatistics.distance_to_target; fill in new field currencies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE CampaignStatistics
                ADD distance_to_target_amountInPence INT NOT NULL,
                ADD distance_to_target_currency VARCHAR(3) NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE Campaign SET match_funds_remaining_currency = 'GBP' WHERE match_funds_remaining_amountInPence = 0;
            UPDATE Campaign SET distance_to_target_currency = 'GBP' WHERE distance_to_target_amountInPence = 0;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics DROP distance_to_target_amountInPence, DROP distance_to_target_currency');
    }
}
