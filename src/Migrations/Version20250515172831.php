<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Patch a donation to get Regression fund balances in sync.
 */
final class Version20250515172831 extends AbstractMigration
{
    private int $donationId = 248739;

    public function getDescription(): string
    {
        return 'Patch a donation to £3 so balances are in sync, only in Regression';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'regression') {
            return;
        }

        $donationId = $this->donationId;
        $this->addSql("UPDATE Donation SET amount = 3 WHERE id = $donationId LIMIT 1");
        $this->addSql("UPDATE FundingWithdrawal SET amount = 3 WHERE donation_id = $donationId LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'regression') {
            return;
        }

        $donationId = $this->donationId;
        $this->addSql("UPDATE Donation SET amount = 1 WHERE id = $donationId LIMIT 1");
        $this->addSql("UPDATE FundingWithdrawal SET amount = 1 WHERE donation_id = $donationId LIMIT 1");
    }
}
