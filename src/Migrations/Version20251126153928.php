<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251126153928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete donor account II';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount
            WHERE DonorAccount.uuid = '1f021204-a400-60e2-a2c8-997daedf9416'
            LIMIT 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
