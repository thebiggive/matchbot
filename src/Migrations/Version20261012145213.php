<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add UKLocationContainment class to record relationships between UK Locations for search
 */
final class Version20261012145213 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE UKLocationContainment
            (
                regionCode         VARCHAR(10)                 NOT NULL,
                ancestorRegionCode VARCHAR(10)                 NOT NULL,
                createdAt          DATETIME                    NOT NULL,
                updatedAt          DATETIME                    NOT NULL,
                id                 INT UNSIGNED AUTO_INCREMENT NOT NULL,
                INDEX regionCode (regionCode),
                INDEX ancestorRegionCode (ancestorRegionCode),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL
);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE UKLocationContainment');
    }
}
