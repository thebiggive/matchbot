<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-161 - add `currencyCode`s.
 */
final class Version20210408183019 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add currencyCode to various tables.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        // Migration modified to first set up existing data with currencyCode 'GBP'.
        $this->addSql('ALTER TABLE Campaign ADD currencyCode VARCHAR(3) NOT NULL DEFAULT \'GBP\'');
        $this->addSql('ALTER TABLE CampaignFunding ADD currencyCode VARCHAR(3) NOT NULL DEFAULT \'GBP\'');
        $this->addSql('ALTER TABLE Donation ADD currencyCode VARCHAR(3) NOT NULL DEFAULT \'GBP\'');
        $this->addSql('ALTER TABLE Fund ADD currencyCode VARCHAR(3) NOT NULL DEFAULT \'GBP\'');

        // Once this change is done, remove the defaults.
        $this->addSql('ALTER TABLE Campaign CHANGE currencyCode currencyCode VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE CampaignFunding CHANGE currencyCode currencyCode VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE currencyCode currencyCode VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE Fund CHANGE currencyCode currencyCode VARCHAR(3) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Campaign DROP currencyCode');
        $this->addSql('ALTER TABLE CampaignFunding DROP currencyCode');
        $this->addSql('ALTER TABLE Donation DROP currencyCode');
        $this->addSql('ALTER TABLE Fund DROP currencyCode');
    }
}
