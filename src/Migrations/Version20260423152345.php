<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423152345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add and populate new Campaign isPublished field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD isPublished TINYINT NOT NULL');
        $this->addSql('UPDATE Campaign SET isPublished = CASE WHEN status IN ("Preview", "Active", "Expired") THEN 1 ELSE 0 END');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP isPublished');
    }
}
