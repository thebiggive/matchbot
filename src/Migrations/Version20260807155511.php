<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807155511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index for Donation.fundsReservedUntil as its used in a WHERE clause';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX fundsReservedUntil ON Donation (fundsReservedUntil)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX fundsReservedUntil ON Donation');
    }
}
