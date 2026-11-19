<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119124831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused column MetaCampaign.inviteOnly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP inviteOnly');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD inviteOnly TINYINT NOT NULL DEFAULT 0');
    }
}
