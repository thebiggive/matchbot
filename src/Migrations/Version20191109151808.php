<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema
 */
final class Version20191109151808 extends AbstractMigration
{
    #[\Override]
    public function getDescription() : string
    {
        return 'Create initial schema';
    }

    #[\Override]
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE Charity (id INT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, salesforceId VARCHAR(18) DEFAULT NULL, salesforceLastPull DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, UNIQUE INDEX UNIQ_4CC08E82D8961D21 (salesforceId), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE Campaign (id INT UNSIGNED AUTO_INCREMENT NOT NULL, charity_id INT UNSIGNED DEFAULT NULL, name VARCHAR(255) NOT NULL, startDate DATETIME NOT NULL, endDate DATETIME NOT NULL, isMatched TINYINT(1) NOT NULL, salesforceId VARCHAR(18) DEFAULT NULL, salesforceLastPull DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, UNIQUE INDEX UNIQ_E663708BD8961D21 (salesforceId), INDEX IDX_E663708BF5C97E37 (charity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE Fund (id INT UNSIGNED AUTO_INCREMENT NOT NULL, amount NUMERIC(18, 2) NOT NULL, name VARCHAR(255) NOT NULL, salesforceId VARCHAR(18) DEFAULT NULL, salesforceLastPull DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, fundType VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_7CA0912ED8961D21 (salesforceId), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE FundingWithdrawal (id INT UNSIGNED AUTO_INCREMENT NOT NULL, donation_id INT UNSIGNED DEFAULT NULL, amount NUMERIC(18, 2) NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_5C8EAC124DC1279C (donation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE CampaignFunding (id INT UNSIGNED AUTO_INCREMENT NOT NULL, fund_id INT UNSIGNED DEFAULT NULL, amount NUMERIC(18, 2) NOT NULL, amountAvailable NUMERIC(18, 2) NOT NULL, allocationOrder INT NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_B00548FA25A38F89 (fund_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE Campaign_CampaignFunding (campaignfunding_id INT UNSIGNED NOT NULL, campaign_id INT UNSIGNED NOT NULL, INDEX IDX_3364399584C3B9E4 (campaignfunding_id), INDEX IDX_33643995F639F774 (campaign_id), PRIMARY KEY(campaignfunding_id, campaign_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE Donation (id INT UNSIGNED AUTO_INCREMENT NOT NULL, campaign_id INT UNSIGNED DEFAULT NULL, uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', transactionId VARCHAR(255) DEFAULT NULL, amount NUMERIC(18, 2) NOT NULL, donationStatus VARCHAR(255) NOT NULL, charityComms TINYINT(1) NOT NULL, giftAid TINYINT(1) NOT NULL, tbgComms TINYINT(1) NOT NULL, donorCountryCode VARCHAR(2) DEFAULT NULL, donorEmailAddress VARCHAR(255) DEFAULT NULL, donorFirstName VARCHAR(255) DEFAULT NULL, donorLastName VARCHAR(255) DEFAULT NULL, donorPostalAddress VARCHAR(255) DEFAULT NULL, donorTitle VARCHAR(255) DEFAULT NULL, salesforceLastPush DATETIME DEFAULT NULL, salesforcePushStatus VARCHAR(255) NOT NULL, salesforceId VARCHAR(18) DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, UNIQUE INDEX UNIQ_C893E3F6D17F50A6 (uuid), UNIQUE INDEX UNIQ_C893E3F6C2F43114 (transactionId), UNIQUE INDEX UNIQ_C893E3F6D8961D21 (salesforceId), INDEX IDX_C893E3F6F639F774 (campaign_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE Campaign ADD CONSTRAINT FK_E663708BF5C97E37 FOREIGN KEY (charity_id) REFERENCES Charity (id)');
        $this->addSql('ALTER TABLE FundingWithdrawal ADD CONSTRAINT FK_5C8EAC124DC1279C FOREIGN KEY (donation_id) REFERENCES Donation (id)');
        $this->addSql('ALTER TABLE CampaignFunding ADD CONSTRAINT FK_B00548FA25A38F89 FOREIGN KEY (fund_id) REFERENCES Fund (id)');
        $this->addSql('ALTER TABLE Campaign_CampaignFunding ADD CONSTRAINT FK_3364399584C3B9E4 FOREIGN KEY (campaignfunding_id) REFERENCES CampaignFunding (id)');
        $this->addSql('ALTER TABLE Campaign_CampaignFunding ADD CONSTRAINT FK_33643995F639F774 FOREIGN KEY (campaign_id) REFERENCES Campaign (id)');
        $this->addSql('ALTER TABLE Donation ADD CONSTRAINT FK_C893E3F6F639F774 FOREIGN KEY (campaign_id) REFERENCES Campaign (id)');
    }

    #[\Override]
    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Campaign DROP FOREIGN KEY FK_E663708BF5C97E37');
        $this->addSql('ALTER TABLE Campaign_CampaignFunding DROP FOREIGN KEY FK_33643995F639F774');
        $this->addSql('ALTER TABLE Donation DROP FOREIGN KEY FK_C893E3F6F639F774');
        $this->addSql('ALTER TABLE CampaignFunding DROP FOREIGN KEY FK_B00548FA25A38F89');
        $this->addSql('ALTER TABLE Campaign_CampaignFunding DROP FOREIGN KEY FK_3364399584C3B9E4');
        $this->addSql('ALTER TABLE FundingWithdrawal DROP FOREIGN KEY FK_5C8EAC124DC1279C');
        $this->addSql('DROP TABLE Charity');
        $this->addSql('DROP TABLE Campaign');
        $this->addSql('DROP TABLE Fund');
        $this->addSql('DROP TABLE FundingWithdrawal');
        $this->addSql('DROP TABLE CampaignFunding');
        $this->addSql('DROP TABLE Campaign_CampaignFunding');
        $this->addSql('DROP TABLE Donation');
    }
}
