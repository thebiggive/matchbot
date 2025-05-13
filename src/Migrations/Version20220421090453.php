<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-25 – prepare donations for claim with new method.
 */
final class Version20220421090453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prepare donations to date for claim with new method';
    }

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
    AND tbgGiftAidRequestQueuedAt < '2022-04-21 00:00:00'
EOT);
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
