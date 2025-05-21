<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Patch a donation to get Regression fund balances in sync.
 *
 * @see Version20250515172831 where I tried to do this but had it backwards. The donation here is a new
 * £5 per donation mandate created to allow correcting regression fund ID 2.
 */
final class Version20250521091607 extends AbstractMigration
{
    private int $donationId = 306679;

    public function getDescription(): string
    {
        return 'Patch a donation to £1 so balances are in sync, only in Regression';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'regression') {
            return;
        }

        $donationId = $this->donationId;
        $this->addSql("UPDATE Donation SET amount = 1 WHERE id = $donationId LIMIT 1");
        $this->addSql("UPDATE FundingWithdrawal SET amount = 1 WHERE donation_id = $donationId LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'regression') {
            return;
        }

        $donationId = $this->donationId;
        $this->addSql("UPDATE Donation SET amount = 5 WHERE id = $donationId LIMIT 1");
        $this->addSql("UPDATE FundingWithdrawal SET amount = 5 WHERE donation_id = $donationId LIMIT 1");
    }
}
