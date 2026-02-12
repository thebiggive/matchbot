<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212165626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CLA-40 Prepare some missed donations for new Gift Aid claim attempts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET tbgGiftAidRequestQueuedAt = NULL
            WHERE
                donationStatus = 'Paid' AND
                giftAid = 1 AND
                tbgShouldProcessGiftAid = 1 AND
                tbgGiftAidRequestQueuedAt IS NOT NULL AND
                tbgGiftAidRequestConfirmedCompleteAt IS NULL AND
                paidOutAt BETWEEN '2026-02-01 00:00:00' AND '2026-02-10 00:00:00' 
            LIMIT 43
        EOT
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
