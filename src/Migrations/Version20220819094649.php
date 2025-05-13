<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-256 re-queue donations on Gift Aid claims rejected due to HMRC enrolment errors.
 */
final class Version20220819094649 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Re-queue 392 donations missed due to HMRC enrolment errors';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $campaignIds = [2596, 3028, 3046];

        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestQueuedAt = NULL,
    tbgGiftAidRequestFailedAt = NULL,
    tbgGiftAidRequestConfirmedCompleteAt = NULL,
    tbgGiftAidRequestCorrelationId = NULL,
    tbgGiftAidResponseDetail = NULL
WHERE
    collectedAt < '2022-08-01 00:00:00' AND
    campaign_id IN (:campaignIdsToRequeue) AND
    tbgGiftAidRequestQueuedAt IS NOT NULL
LIMIT 392
EOT,
            ['campaignIdsToRequeue' => $campaignIds],
            ['campaignIdsToRequeue' => ArrayParameterType::INTEGER],
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
