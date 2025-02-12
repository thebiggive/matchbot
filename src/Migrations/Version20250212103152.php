<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2821 – Same as first query from {@see Version20250211130658} but without the typo.
 */
final class Version20250212103152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-re-queue 1 campaign\'s donations for Gift Aid';
    }

    public function up(Schema $schema): void
    {
        // 1 campaign with 64 donations where submission was acknowledged but HMRC have asked us to resubmit.
        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestQueuedAt = NULL,
    tbgGiftAidRequestFailedAt = NULL,
    tbgGiftAidRequestConfirmedCompleteAt = NULL,
    tbgGiftAidRequestCorrelationId = NULL,
    tbgGiftAidResponseDetail = NULL
WHERE
    collectedAt > '2024-03-19 00:00:00' AND
    campaign_id = 6364 AND
    tbgGiftAidRequestQueuedAt IS NOT NULL
LIMIT 64
EOT,
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
