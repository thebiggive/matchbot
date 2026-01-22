<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260120164642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make campaign summary nullable to match salesforce';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE summary summary VARCHAR(5000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE summary summary VARCHAR(5000) NOT NULL');
    }
}
