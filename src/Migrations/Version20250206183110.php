<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250206183110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Donation.tipRefundAmount';
    }

    public function up(Schema $schema): void
    {
        // All generated by vendor/bin/doctrine-migrations migrations:diff, second statement should have been part of commit
        // on an earlier day.

        $this->addSql('ALTER TABLE Donation ADD tipRefundAmount NUMERIC(18, 2) DEFAULT NULL');

        $this->addSql(
        <<<'SQL'
            ALTER TABLE RegularGivingMandate CHANGE tbgComms tbgComms TINYINT(1) NOT NULL, 
            CHANGE charityComms charityComms TINYINT(1) NOT NULL
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP tipRefundAmount');
        $this->addSql(<<<'SQL'
            ALTER TABLE RegularGivingMandate CHANGE tbgComms tbgComms TINYINT(1) DEFAULT 0 NOT NULL,
            CHANGE charityComms charityComms TINYINT(1) DEFAULT 0 NOT NULL
            SQL
        );
    }
}
