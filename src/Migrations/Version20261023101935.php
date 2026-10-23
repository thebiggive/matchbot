<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

/**
 * I think these old donations in regtest environment relate to deleted campaigns in Salesforce so cannot be pushed and
 * don't need to be. Without this the system keeps trying to push them and generating error alerts.
 */
final class Version20261023101935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stop trying to push old regtest environment donations to salesforce';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Regression) {
            return;
        }

        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'complete' WHERE salesforcePushStatus='pending-update' AND createdAt < '2026-09-01' LIMIT 50");
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
