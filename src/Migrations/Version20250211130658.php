<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2821 – re-queue donations for Gift Aid not yet processed due to data & code blips.
 */
final class Version20250211130658 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Re-queue 4 campaigns\' donations for Gift Aid';
    }

    #[\Override]
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
    collectedAt < '2024-03-19 00:00:00' AND
    campaign_id = 6364 AND
    tbgGiftAidRequestQueuedAt IS NOT NULL
LIMIT 64
EOT,
        );

        // 18 donations across 3 campaigns where submission was never acnowledged.
        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestQueuedAt = NULL
WHERE
    tbgGiftAidRequestQueuedAt >= '2024-10-28 00:00:00' AND
    tbgGiftAidRequestConfirmedCompleteAt IS NULL AND
    tbgGiftAidRequestCorrelationId IS NULL AND
    tbgGiftAidRequestFailedAt IS NULL AND
    campaign_id IN (7215, 7232, 8314)
LIMIT 18
EOT,
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
