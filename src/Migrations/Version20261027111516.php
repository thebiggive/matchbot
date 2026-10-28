<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20261027111516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cancel old mandates and PreAuthorized donations in Regression environment';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Regression) {
            return;
        }

        $this->addSql("UPDATE RegularGivingMandate SET status = 'cancelled', salesforcePushStatus = 'complete' WHERE status = 'active' AND createdAt < '2026-10-27 00:00:00'");
        $this->addSql("UPDATE Donation SET donationStatus = 'Cancelled', salesforcePushStatus = 'complete' WHERE donationStatus = 'PreAuthorized' AND createdAt < '2026-10-27 00:00:00'");
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
