<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240304105340 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'no-op migration';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // no-op
        // Previous implementation deleted but skeleton of class restored as Doctrine Migrations doesn't
        // like a migration being missing from files when its knows from the DB (in staging) that it was executed
        // previously.
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no-op
    }
}
