<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * See BG2-2550
 */
final class Version20240206145629 extends AbstractMigration
{
    #[\Override]
    public function up(Schema $schema): void
    {
        $campaignIdForBG22550 = '5041';

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
                campaign_id = $campaignIdForBG22550 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 73;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("No going back");
    }
}
