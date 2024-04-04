<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240404134524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove spurious data - see ticket MAT-220';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
                UPDATE Donation SET donorEmailAddress = REGEXP_REPLACE(donorEmailAddress, '[0-9]+$', '')
                WHERE Donation.donorEmailAddress RLIKE "[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$"
                AND Donation.createdAt < '2022-01-01'
                AND Donation.createdAt > '2021-11-01'
                LIMIT 14;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
       throw new \Exception("no going back");
    }
}
