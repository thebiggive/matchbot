<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251126143746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount
            WHERE DonorAccount.uuid = '1f02115c-c44a-6616-87b4-772aa283cb38'
            LIMIT 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
