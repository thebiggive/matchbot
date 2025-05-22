<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250522120626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove new view that failed to deploy everywhere due to permissions issue.';
    }

    public function up(Schema $schema): void
    {
        try {
            $this->addSql(<<<'SQL'
                drop view if exists `donation-summary-no-orm`;
            SQL);
        } catch (\Exception) {
            // possible permissions issue but in that case the view wouldn't have been created in this environment so
            // no need to do anything.
        }
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
