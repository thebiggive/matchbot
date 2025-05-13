<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add link between FundingWithdrawal and CampaignFunding
 */
final class Version20191110112409 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add link between FundingWithdrawal and CampaignFunding';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE FundingWithdrawal ADD campaignFunding_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE FundingWithdrawal ADD CONSTRAINT FK_5C8EAC12CB9EBA34 FOREIGN KEY (campaignFunding_id) REFERENCES CampaignFunding (id)');
        $this->addSql('CREATE INDEX IDX_5C8EAC12CB9EBA34 ON FundingWithdrawal (campaignFunding_id)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE FundingWithdrawal DROP FOREIGN KEY FK_5C8EAC12CB9EBA34');
        $this->addSql('DROP INDEX IDX_5C8EAC12CB9EBA34 ON FundingWithdrawal');
        $this->addSql('ALTER TABLE FundingWithdrawal DROP campaignFunding_id');
    }
}
