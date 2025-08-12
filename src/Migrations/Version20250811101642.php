<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250811101642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fill in expected match amount with dummy data on old donations - fix errors coming in regression alarms now.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation set expectedMatchAmount_currency = "GBP" where expectedMatchAmount_currency = ""
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
