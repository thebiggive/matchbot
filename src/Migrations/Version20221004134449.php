<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-259 Re-queue every single donation for LAW CENTRES FEDERATION and CYMDEITHAS ERYRI THE SNOWDONIA SOCIETY
 */
final class Version20221004134449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MAT-259 Re-queue every single donation for LAW CENTRES FEDERATION and CYMDEITHAS ERYRI THE SNOWDONIA SOCIETY';
    }

    public function up(Schema $schema): void
    {
        $campaignIds = [1805, 2758, 2469];
        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestQueuedAt = NULL,
    tbgGiftAidRequestFailedAt = NULL,
    tbgGiftAidRequestConfirmedCompleteAt = NULL,
    tbgGiftAidRequestCorrelationId = NULL,
    tbgGiftAidResponseDetail = NULL
WHERE
    campaign_id IN (:campaignIdsToRequeue) AND
    tbgGiftAidRequestQueuedAt IS NOT NULL AND
    giftAid = 1 AND
    tbgShouldProcessGiftAid = 1
LIMIT 130
EOT,
            ['campaignIdsToRequeue' => $campaignIds],
            ['campaignIdsToRequeue' => ArrayParameterType::INTEGER],
        );
    }
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
