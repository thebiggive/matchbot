<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240410102808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resubmit gift aid claims - see BG2-2595';
    }

    public function up(Schema $schema): void
    {
        $campaignIdForBG2_2595 = "6178";

        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL,
                salesforcePushStatus = 'pending-update'
            WHERE
                campaign_id = $campaignIdForBG2_2595 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 73;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("No going back");
    }
}
