<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240313113938 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'See MAT-357';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $campaignIdForMat357 = '4684';
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
                campaign_id = $campaignIdForMat357 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 53
        SQL);

        // 53 above is result of running SELECT count(*) FROM Donation
        //                               WHERE Donation.campaign_id = "4684"
        //                               AND donationStatus = "Paid" AND giftAid = 1;
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("No back-takesies!");
    }
}
