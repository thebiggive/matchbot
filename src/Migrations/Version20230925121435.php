<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230925121435 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove Donation.clientSecret property';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP clientSecret');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD clientSecret VARCHAR(255) DEFAULT NULL');
    }
}
