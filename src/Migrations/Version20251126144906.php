<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

/**
 * BG2-2950 Test run of moving champion funds around post-campaign.
 */
final class Version20251126144906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Staging only: move champion funds post-campaign';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Staging) {
            return;
        }

        $this->addSql('UPDATE FundingWithdrawal SET campaignFunding_id = 9445 WHERE campaignFunding_id = 283');
        $this->addSql('UPDATE FundingWithdrawal SET campaignFunding_id = 9446 WHERE campaignFunding_id = 289');
        $this->addSql(<<<SQL
            UPDATE CampaignFunding SET amountAvailable = amount WHERE id IN (283, 289)
            LIMIT 2
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
