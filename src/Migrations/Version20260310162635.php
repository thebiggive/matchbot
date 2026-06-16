<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-3112 Re-queue Gift Aid claims for some donations paid out December & impacted by CLA-40.
 */
final class Version20260310162635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-queue Gift Aid claims for one further charity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET tbgGiftAidRequestQueuedAt = NULL
            WHERE
                donationStatus = 'Paid' AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestQueuedAt IS NOT NULL AND
                tbgGiftAidRequestConfirmedCompleteAt IS NULL AND
                tbgGiftAidRequestFailedAt IS NULL AND
                paidOutAt BETWEEN '2025-12-01 00:00:00' AND '2026-02-10 00:00:00' AND
                campaign_id = 30075
            LIMIT 71
        EOT
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
