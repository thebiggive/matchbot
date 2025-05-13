<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-253 re-queue end of June donations sent to old SQS queue.
 */
final class Version20220705130951 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Re-queue end of June donations sent to old SQS queue';
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
    tbgGiftAidRequestQueuedAt BETWEEN '2022-06-27 00:00:00' AND '2022-06-27 23:59:59' AND
    tbgGiftAidRequestCorrelationId IS NULL AND
    tbgGiftAidRequestFailedAt IS NULL
LIMIT 148
EOT);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
