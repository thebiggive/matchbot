<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230801110114 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Resubmit Gift Aid claims (MAT-308)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // SQL copied from \MatchBot\Migrations\Version20230707100241, campaign id changed.
        $this->addSql(<<<SQL
            UPDATE Donation
            SET
                tbgGiftAidRequestQueuedAt = NULL,
                tbgGiftAidRequestFailedAt = NULL,
                tbgGiftAidRequestConfirmedCompleteAt = NULL,
                tbgGiftAidRequestCorrelationId = NULL,
                tbgGiftAidResponseDetail = NULL
            WHERE
                campaign_id = 4644 AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestCorrelationId IS NOT NULL AND
                donationStatus = 'Paid'
            LIMIT 97;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("No going back");
    }
}
