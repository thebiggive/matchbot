<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-351 – resubmit one campaign's donations as requested by HMRC.
 */
final class Version20240102160835 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Resubmit donations as requested by HMRC';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $campaignIdForMat351 = 4741;

        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL
            WHERE
                campaign_id = $campaignIdForMat351 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 45;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
