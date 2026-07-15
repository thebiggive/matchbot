<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * {@see \MatchBot\Migrations\Version20260422140404 } which was similar.
 */
final class Version20260714150615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resubmit gift aid for one donation';
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
                salesforceId = 'a06WS00000HOV0vYAH'
                LIMIT 1
          EOT
            );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('no going back');
    }
}
