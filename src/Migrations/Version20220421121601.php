<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-25 – prepare missed donations for claim with new method.
 */
final class Version20220421121601 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Prepare remaining donations for claim with new method';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestQueuedAt = NULL,
    tbgGiftAidRequestFailedAt = NULL,
    tbgGiftAidRequestConfirmedCompleteAt = NULL,
    tbgGiftAidRequestCorrelationId = NULL,
    tbgGiftAidResponseDetail = NULL
WHERE
    tbgGiftAidRequestQueuedAt IS NOT NULL
    AND tbgGiftAidRequestQueuedAt > '2022-04-21 00:00:00'
    AND tbgGiftAidRequestQueuedAt < '2022-04-21 15:00:00'
    AND tbgGiftAidRequestConfirmedCompleteAt IS NULL
EOT); // Only reset things queued today (21 April) up to 16:00 BST.
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
