<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add CampaignStatistics.match_funds_remaining.
 */
final class Version20250708103029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CampaignStatistics.match_funds_remaining';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE CampaignStatistics
                ADD match_funds_remaining_amountInPence INT NOT NULL,
                ADD match_funds_remaining_currency VARCHAR(3) NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics DROP match_funds_remaining_amountInPence, DROP match_funds_remaining_currency');
    }
}
