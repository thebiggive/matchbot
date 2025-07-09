<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250708101043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resubmit GA claims for two charities';
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
                campaign_id = 6595
                AND donationStatus in ('paid', 'collected')
                AND giftAid = 1
            LIMIT 94 
            SQL
        );

        $this->addSql(<<<'SQL'
            UPDATE Donation SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL,
                salesforcePushStatus = 'pending-update'
            WHERE 
                campaign_id IN (7379, 8897)
                AND donationStatus in ('paid', 'collected')
                AND giftAid = 1
            LIMIT 948 
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('No going back');
    }
}
