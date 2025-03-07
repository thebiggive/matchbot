<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250307155647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add logoUri and websiteURI columns to charity entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD logoUri VARCHAR(255) DEFAULT NULL, ADD websiteURI VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP logoUri, DROP websiteURI');
    }
}
