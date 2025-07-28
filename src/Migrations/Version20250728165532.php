<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250728165532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ALTER TABLE MetaCampaign ADD campaignFamily ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD campaignFamily VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP campaignFamily');
    }
}
