<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20261027130857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cancel old Paid donations in Regression environment';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Regression) {
            return;
        }

        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'complete' WHERE donationStatus = 'Paid' AND salesforcePushStatus = 'pending-update' AND createdAt < '2026-10-27 01:00:00'");
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
