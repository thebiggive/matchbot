<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250326114446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete unwanted donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount 
            WHERE DonorAccount.uuid = '1ee847a0-9658-6f86-af6d-f38c5f5129cc'
            LIMIT 1
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no un-patch.
    }
}
