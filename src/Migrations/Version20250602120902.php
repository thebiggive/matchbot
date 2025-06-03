<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250602120902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create table MetaCampaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE MetaCampaign (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                slug VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                currency VARCHAR(255) NOT NULL,
                status VARCHAR(255) NOT NULL,
                hidden TINYINT(1) NOT NULL,
                summary VARCHAR(255),
                bannerURI VARCHAR(255), 
                startDate DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', 
                endDate DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', 
                isRegularGiving TINYINT(1) NOT NULL, 
                isEmergencyIMF TINYINT(1) NOT NULL, 
                salesforceLastPull DATETIME DEFAULT NULL, 
                salesforceId VARCHAR(18) DEFAULT NULL, 
                UNIQUE INDEX UNIQ_C36155ECD8961D21 (salesforceId), 
                PRIMARY KEY(id)
            ) 
            DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL
);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE MetaCampaign');
    }
}
