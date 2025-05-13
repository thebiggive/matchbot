<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250312154337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Charity.emailAddress';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD emailAddress VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP emailAddress');
    }
}
