<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250520170950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove plan to claim GA for one charity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Donation 
            SET matchbot.Donation.tbgShouldProcessGiftAid = 0
            WHERE Donation.campaign_id = 8963 and Donation.donationStatus in ('paid', 'collected')
            LIMIT 500; -- this is more than the actual number of donations, not wishing to publish the exact figure.
            SQL
        );
    }

    public function down(Schema $schema): void
    {        $this->addSql(<<<'SQL'
            UPDATE Donation 
            SET matchbot.Donation.tbgShouldProcessGiftAid = 1 -- was 1 in all cases before running up migration
            WHERE Donation.campaign_id = 8963 and Donation.donationStatus in ('paid', 'collected')
            LIMIT 500;
            SQL);
    }
}
