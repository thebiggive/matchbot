<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241202160659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove DB level default for isRegularGiving - no longer needed, default is now in CampaignRepository class';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE isRegularGiving isRegularGiving TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE isRegularGiving isRegularGiving TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
