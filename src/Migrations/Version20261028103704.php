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
        if (Environment::current() !== Environment::Regression) {
            return;
        }

        $this->addSql("DELETE FROM Donation WHERE createdAt < '2026-10-27 01:00:00'");
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
