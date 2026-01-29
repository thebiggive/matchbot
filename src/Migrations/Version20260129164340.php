<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260129164340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update donor account table to support organisations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD organisationName VARCHAR(255) DEFAULT NULL, ADD isOrganisation TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount DROP organisationName, DROP isOrganisation');
    }
}
