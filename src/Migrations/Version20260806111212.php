<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806111212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index for Donation.collectedAt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX collectedAt ON Donation (collectedAt)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX collectedAt ON Donation');
    }
}
