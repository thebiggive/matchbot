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
        $this->addSql('ALTER TABLE FundingWithdrawal CHANGE donation_id donation_id INT UNSIGNED NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE FundingWithdrawal CHANGE donation_id donation_id INT UNSIGNED DEFAULT NULL');
    }
}
