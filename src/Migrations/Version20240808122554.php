<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240808122554 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return '';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
                CREATE TABLE RegularGivingMandate
                (
                    id                   INT UNSIGNED AUTO_INCREMENT NOT NULL,
                    uuid                 VARCHAR(255)                NOT NULL,
                    campaignId           VARCHAR(255)                NOT NULL,
                    charityId            VARCHAR(255)                NOT NULL,
                    giftAid              TINYINT(1)                  NOT NULL,
                    salesforceLastPush   DATETIME    DEFAULT NULL,
                    salesforcePushStatus VARCHAR(255)                NOT NULL,
                    salesforceId         VARCHAR(18) DEFAULT NULL,
                    createdAt            DATETIME                    NOT NULL,
                    updatedAt            DATETIME                    NOT NULL,
                    personid             CHAR(36)                    NOT NULL COMMENT '(DC2Type:uuid)',
                    amount_amountInPence INT                         NOT NULL,
                    amount_currency      VARCHAR(255)                NOT NULL,
                    UNIQUE INDEX UNIQ_F638CA2BD17F50A6 (uuid),
                    UNIQUE INDEX UNIQ_F638CA2BD8961D21 (salesforceId),
                    INDEX uuid (uuid),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
                  COLLATE `utf8mb4_unicode_ci`
                  ENGINE = InnoDB
                SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE RegularGivingMandate');
    }
}
