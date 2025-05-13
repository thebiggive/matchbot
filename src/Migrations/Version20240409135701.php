<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2598 – add index Donation.updated_date_and_status
 */
final class Version20240409135701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index Donation.updated_date_and_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX updated_date_and_status ON Donation (updatedAt, donationStatus)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX updated_date_and_status ON Donation');
    }
}
