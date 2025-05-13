<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230925121435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Donation.clientSecret property';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP clientSecret');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD clientSecret VARCHAR(255) DEFAULT NULL');
    }
}
