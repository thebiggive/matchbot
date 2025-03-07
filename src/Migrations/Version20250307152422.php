<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250307152422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add salesforceData column to campaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD salesforceData JSON NOT NULL DEFAULT (\'{}\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP salesforceData');
    }
}
