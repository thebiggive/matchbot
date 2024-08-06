<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RegularGivingMandate table creation. Auto generated with
 * vendor/bin/doctrine-migrations diff, only comments and whitespace edited.
 */
final class Version20240806170446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE RegularGivingMandate (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                uuid CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
                salesforceLastPush DATETIME DEFAULT NULL,
                salesforcePushStatus VARCHAR(255) NOT NULL,
                salesforceId VARCHAR(18) DEFAULT NULL,
                createdAt DATETIME NOT NULL,
                updatedAt DATETIME NOT NULL,
                amount_amountInPence INT NOT NULL,
                amount_currency VARCHAR(255) NOT NULL,
                UNIQUE INDEX UNIQ_F638CA2BD17F50A6 (uuid),
                UNIQUE INDEX UNIQ_F638CA2BD8961D21 (salesforceId),
                INDEX uuid (uuid),
                PRIMARY KEY(id)) 
                DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL
            );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE RegularGivingMandate');
    }
}
