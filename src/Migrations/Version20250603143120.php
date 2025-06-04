<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Patch a regression donation's total amount charged. {@see Version20250521091607} where `amount` was changed.
 */
final class Version20250603143120 extends AbstractMigration
{
    private int $donationId = 306679;

    public function getDescription(): string
    {
        return 'Patch a donation amount charged to £1 so assertions pass, only in Regression';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'regression') {
            return;
        }

        $donationId = $this->donationId;
        $this->addSql("UPDATE Donation SET totalPaidByDonor = 1 WHERE id = $donationId AND amount = 1 LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'regression') {
            return;
        }

        $donationId = $this->donationId;
        $this->addSql("UPDATE Donation SET totalPaidByDonor = 5 WHERE id = $donationId AND amount = 5 LIMIT 1");
    }
}
