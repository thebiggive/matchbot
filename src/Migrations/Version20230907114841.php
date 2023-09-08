<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230907114841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resubmit Gift Aid claims (MAT-311)';
    }

    public function up(Schema $schema): void
    {
        $campaignIdForMAT311 = 4661;

        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL
            WHERE
                campaign_id = $campaignIdForMAT311 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 218;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("No going back");
    }
}
