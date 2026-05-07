<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505135214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused campaign status field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD status VARCHAR(64) DEFAULT NULL');
    }
}
