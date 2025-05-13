<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241129170406 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create isRegularGiving column';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD isRegularGiving TINYINT(1) DEFAULT 0 NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP isRegularGiving');
    }
}
