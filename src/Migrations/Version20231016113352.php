<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231016113352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Campaign.charity_id non-nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE charity_id charity_id INT UNSIGNED NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign CHANGE charity_id charity_id INT UNSIGNED DEFAULT NULL');
    }
}
