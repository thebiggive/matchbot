<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105172530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused CommandLockKeys table - now replaced by Redis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE CommandLockKeys');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE CommandLockKeys (key_id VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, key_token VARCHAR(44) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY(key_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB COMMENT = \'\' ');
    }
}
