<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Have many-many table follow others\' naming convention; add CampaignFunding.amountAvailable
 */
final class Version20191029113005 extends AbstractMigration
{
    public function getDescription() : string
    {
        return "Have many-many table follow others\' naming convention; add CampaignFunding.amountAvailable";
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE Campaign_CampaignFunding (campaignfunding_id INT UNSIGNED NOT NULL, campaign_id INT UNSIGNED NOT NULL, INDEX IDX_3364399584C3B9E4 (campaignfunding_id), INDEX IDX_33643995F639F774 (campaign_id), PRIMARY KEY(campaignfunding_id, campaign_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE Campaign_CampaignFunding ADD CONSTRAINT FK_3364399584C3B9E4 FOREIGN KEY (campaignfunding_id) REFERENCES CampaignFunding (id)');
        $this->addSql('ALTER TABLE Campaign_CampaignFunding ADD CONSTRAINT FK_33643995F639F774 FOREIGN KEY (campaign_id) REFERENCES Campaign (id)');
        $this->addSql('DROP TABLE campaignfunding_campaign');
        $this->addSql('ALTER TABLE CampaignFunding ADD amountAvailable NUMERIC(18, 2) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE campaignfunding_campaign (campaignfunding_id INT UNSIGNED NOT NULL, campaign_id INT UNSIGNED NOT NULL, INDEX IDX_696E7E3484C3B9E4 (campaignfunding_id), INDEX IDX_696E7E34F639F774 (campaign_id), PRIMARY KEY(campaignfunding_id, campaign_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE campaignfunding_campaign ADD CONSTRAINT FK_696E7E3484C3B9E4 FOREIGN KEY (campaignfunding_id) REFERENCES CampaignFunding (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE campaignfunding_campaign ADD CONSTRAINT FK_696E7E34F639F774 FOREIGN KEY (campaign_id) REFERENCES Campaign (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('DROP TABLE Campaign_CampaignFunding');
        $this->addSql('ALTER TABLE CampaignFunding DROP amountAvailable');
    }
}
