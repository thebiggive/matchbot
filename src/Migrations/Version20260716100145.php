<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716100145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resubmit donations for one campaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL,
                salesforcePushStatus = 'pending-update'
            WHERE
                donationStatus in ('Paid', 'Collected') AND 
                giftAid = 1 AND 
                tbgShouldProcessGiftAid = 1 AND
                campaign_id = 32235
                LIMIT 1000 -- <- 1,000 is higher than the actual number of donations, not sharing the exact number here.
          EOT
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('no going back');
    }
}
