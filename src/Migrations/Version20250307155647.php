<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250307155647 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add logoUri and websiteUri columns to charity entity';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD logoUri VARCHAR(255) DEFAULT NULL, ADD websiteUri VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP logoUri, DROP websiteUri');
    }
}
