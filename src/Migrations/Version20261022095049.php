<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20261022095049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stop pushing ex-donations to regression Salesforce';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Regression) {
            return;
        }

        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'complete' WHERE salesforcePushStatus = 'pending-update' AND salesforceLastPush < '2026-10-10' LIMIT 42");
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
