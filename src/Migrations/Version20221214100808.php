<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20221214100808 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Update data about partially refund donation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                UPDATE Donation 
                set amount = 450 , salesforcePushStatus = 'pending-update'
                WHERE uuid = "dcec4e60-8969-4f40-80f0-35bb5b7184af" LIMIT 1
            SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no-op
    }
}
