<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240304105340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set donations to paid for two campaigns from CC23 - as mentioned on Jira we 
        know these were paid but for some reason Matchbot failed to get updated by Stripe to show that.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation SET donationStatus = 'Paid' 
                            WHERE donationStatus = 'Collected'
                            AND campaign_id IN (5483, 5071)
                            LIMIT 136
SQL
);
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('Non reversible migration');
    }
}
