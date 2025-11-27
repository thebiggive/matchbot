<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

/**
 * * BG2-2950 Final piece of test run of moving champion funds around post-campaign.
 */
final class Version20251127060641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Staging only: update new fundig amountAvailable';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Staging) {
            return;
        }

        // "Funding 9446 is over-matched by 2237.00. Donation withdrawals 2237.00, funding allocations 0.00"
        $this->addSql(<<<SQL
            UPDATE CampaignFunding SET amountAvailable = amount - 2237 WHERE id = 9446
            LIMIT 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
