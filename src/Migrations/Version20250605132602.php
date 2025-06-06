<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-submit Gift Aid claims for one charity (MAT-427)
 */
final class Version20250605132602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-submit Gift Aid claims for one charity (MAT-427)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL
            WHERE
                campaign_id = 7774 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 83;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
