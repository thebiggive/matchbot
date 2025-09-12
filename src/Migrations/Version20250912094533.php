<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixing alarms / historic CampaignFunding amounts:
 *
 * Matchbot ERROR: Funding ID 38894 balance could not be negative-increased by £-2000.00. Salesforce Fund ID a0AWS000007gaPd2AI as campaign a05WS000002O
 * Matchbot ERROR: Funding ID 38896 balance could not be negative-increased by £-1000.00. Salesforce Fund ID a0AWS000007ib9V2AQ as campaign a05WS000002O
 */
final class Version20250912094533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reduce amount in CampaignFunding to reverse accidental post-campaign increases in Salesforce (already reversed there)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE CampaignFunding SET amount = 500, amountAvailable = 0 WHERE id = 38894 AND amount = 2500 AND amountAvailable = 2000;
        SQL
        );
        $this->addSql(<<<'SQL'
            UPDATE CampaignFunding SET amount = 500, amountAvailable = 0 WHERE id = 38896 AND amount = 1500 AND amountAvailable = 1000;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
