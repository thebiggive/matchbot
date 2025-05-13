<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Patch total charged following £0'd tip from 1 missed donation
 * @see Version20241206101546
 */
final class Version20241218162432 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return "Patch total charged following £0'd tip from 1 missed donation";
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', totalPaidByDonor = 29
            WHERE uuid = 'b17948f2-8871-41c3-b190-c0383ffb73d2' AND transactionId = 'pi_3QRxU2KkGuKkxwBN1Rw88X1Y' AND tipAmount = 0
            LIMIT 1
        EOT
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
