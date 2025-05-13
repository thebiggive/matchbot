<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250307152422 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add salesforceData column to campaign';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD salesforceData JSON NOT NULL');
        $this->addSql('UPDATE Campaign SET salesforceData = \'{}\' where ID > 0');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP salesforceData');
    }
}
