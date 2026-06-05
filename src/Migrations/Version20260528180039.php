<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SO-78 Add CampaignLocation
 */
final class Version20260528180039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CampaignLocation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE CampaignLocation (countryName VARCHAR(100) DEFAULT NULL, regionCode VARCHAR(10) DEFAULT NULL, id INT UNSIGNED AUTO_INCREMENT NOT NULL, campaign_id INT UNSIGNED NOT NULL, INDEX IDX_6C25EDB1F639F774 (campaign_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE CampaignLocation ADD CONSTRAINT FK_6C25EDB1F639F774 FOREIGN KEY (campaign_id) REFERENCES Campaign (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignLocation DROP FOREIGN KEY FK_6C25EDB1F639F774');
        $this->addSql('DROP TABLE CampaignLocation');
    }
}
