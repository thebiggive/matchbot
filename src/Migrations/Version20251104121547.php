<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-3001 - delete donor account
 */
final class Version20251104121547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BG2-3001 - delete donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount 
            WHERE DonorAccount.uuid = '1ed6fde2-f191-689e-8d9c-6fb7d44da103'
            LIMIT 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no un-patch
    }
}
