<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250107184108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make DonorAccount.uuid non-nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount CHANGE uuid uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount CHANGE uuid uuid CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
    }
}
