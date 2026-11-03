<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

/**
 * Similar to @see Version20261027130857 but without constraint on statuses and deleting not changing status
 */
final class Version20261028103704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete any donations made before today in Regression to stop push to SF failure alarms';
    }

    public function up(Schema $schema): void
    {
        // migration edited to be no-op - previously was only for regression environment but was to slow
        // to run successfully.
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
