<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250506171108 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove some unused nullability in join columns';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('ALTER TABLE Campaign CHANGE charity_id charity_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE CampaignFunding CHANGE fund_id fund_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE campaign_id campaign_id INT UNSIGNED NOT NULL');

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('ALTER TABLE Campaign CHANGE charity_id charity_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE CampaignFunding CHANGE fund_id fund_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE campaign_id campaign_id INT UNSIGNED DEFAULT NULL');

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
