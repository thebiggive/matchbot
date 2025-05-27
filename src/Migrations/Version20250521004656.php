<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250521004656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payout related fields to donation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE Donation 
                ADD stripePayoutId VARCHAR(255) DEFAULT NULL, 
                ADD paidOutAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
            SQL
            );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP stripePayoutId, DROP paidOutAt');
    }
}
