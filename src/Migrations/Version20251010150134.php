<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251010150134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change Campaign.relatedApplicationStatus db type as now an enum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE relatedApplicationStatus relatedApplicationStatus VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE relatedApplicationStatus relatedApplicationStatus VARCHAR(64) DEFAULT NULL');
    }
}
