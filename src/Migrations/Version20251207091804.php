<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-3046 Patch data for a part-refunded tip.
 */
final class Version20251207091804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Patch data for a part-refunded tip';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', tipAmount = 1, totalPaidByDonor = 51,
                refundedAt = '2025-12-06 12:54:00', tipRefundAmount = 6.5
            WHERE uuid = '50ec31ab-3695-4e78-b5f3-d4ee3e394447' AND transactionId = 'pi_3SbJuzKkGuKkxwBN0KBDtAXf'
            LIMIT 1
        EOT);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', tipAmount = 7.5, totalPaidByDonor = 57.5,
                refundedAt = NULL, tipRefundAmount = NULL
            WHERE uuid = '50ec31ab-3695-4e78-b5f3-d4ee3e394447' AND transactionId = 'pi_3SbJuzKkGuKkxwBN0KBDtAXf'
            LIMIT 1
        EOT);
    }
}
