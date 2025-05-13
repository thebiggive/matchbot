<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241120172245 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add status column to campaign';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD status VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP status');
    }
}
