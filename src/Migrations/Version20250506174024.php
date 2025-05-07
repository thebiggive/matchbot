<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250506174024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make FundingWithdrawal.donation_id non-null to match actual usage';
    }

    public function up(Schema $schema): void
    {
        // SET FOREIGN_KEY_CHECKS required to allow changing type of a foreign key, even when making
        // it narrower. I believe this only affects this connection, and we don't rely purely on these checks for
        // integrity in any case.
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('ALTER TABLE FundingWithdrawal CHANGE donation_id donation_id INT UNSIGNED NOT NULL');

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('ALTER TABLE FundingWithdrawal CHANGE donation_id donation_id INT UNSIGNED DEFAULT NULL');

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
