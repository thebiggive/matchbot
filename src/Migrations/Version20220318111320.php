<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2014 – un-mark donations for TBG Gift Aid claiming where orgs have told us they will claim it
 * themselves.
 */
final class Version20220318111320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un-mark donations for TBG Gift Aid claiming where orgs have told us they will claim it themselves';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation SET tbgShouldProcessGiftAid = 0
            WHERE campaign_id IN (2078, 2165, 2454) AND tbgShouldProcessGiftAid = 1
            LIMIT 52
EOT
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
