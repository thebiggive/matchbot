<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251230210427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete unwanted donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount
            WHERE DonorAccount.uuid = '1ede2af3-f901-6c88-b17d-d9e14716d0e9'
            LIMIT 1
            SQL
        );
    }
}
