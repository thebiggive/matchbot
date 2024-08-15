<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240813133442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resubmit gift aid claims for campaign # 6620';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Donation SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL,
                salesforcePushStatus = 'pending-update'
            WHERE 
                campaign_id = 6620
                AND donationStatus in ('paid', 'collected')
                AND giftAid = 1
            LIMIT 500
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back");
    }
}
