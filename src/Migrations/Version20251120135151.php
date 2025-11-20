<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251120135151 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount 
            WHERE DonorAccount.uuid = '1ee847c5-6ee7-6d6a-84b3-ddd3a3a94d0e'
            LIMIT 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
