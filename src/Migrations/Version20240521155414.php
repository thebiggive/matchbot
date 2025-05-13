<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240521155414 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Resubmit gift aid claims - see MAT-367';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $campaignIDForMAT367 = "4644";
        $countForMAT367 = "97";

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
                campaign_id = $campaignIDForMAT367 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT $countForMAT367;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("No going back");
    }
}
