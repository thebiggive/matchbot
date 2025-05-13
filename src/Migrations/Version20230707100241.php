<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-303 Gift Aid re-claim
 */
final class Version20230707100241 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Re-claim Gift Aid for a charity who were incorrectly enrolled the first time';
    }

    #[\Override]
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
                campaign_id = 4635 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 224;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
