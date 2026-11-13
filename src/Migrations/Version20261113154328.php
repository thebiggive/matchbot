<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113154328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inviteOnly column to MetaCampaign for excluding future invite-only meta-campaigns from sitemap';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD inviteOnly TINYINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP inviteOnly');
    }
}
