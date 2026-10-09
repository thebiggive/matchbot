<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009155806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for CampaignLocation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX regionCode ON CampaignLocation (regionCode)');
        $this->addSql('CREATE INDEX countryName ON CampaignLocation (countryName)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX regionCode ON CampaignLocation');
        $this->addSql('DROP INDEX countryName ON CampaignLocation');
    }
}
