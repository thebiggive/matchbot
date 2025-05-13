<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240503091515 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop unused column Fund.amount';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Fund DROP amount');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Fund ADD amount NUMERIC(18, 2) DEFAULT NULL');
    }
}
