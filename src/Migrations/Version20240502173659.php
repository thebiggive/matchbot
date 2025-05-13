<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * We want to delete the Fund.amount column, as it isn't useful and is confusing,
 * but we can't do that until after deploying the PHP changes to stop reading that column. For now we make it default
 * null so we don't have to write to it.
 *
 * I don't think we can use the ORM to generate the migration for a two step process like this.
 */
final class Version20240502173659 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Make Fund.amount nullable / default null';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Fund MODIFY COLUMN amount DECIMAL(18, 2) NULL DEFAULT NULL;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE Fund MODIFY COLUMN amount DECIMAL(18, 2) NOT NULL;
        SQL
        );
    }
}
