<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240716142043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op migration - replaces version that failed in prod, file kept to match staging db that says this was run.';
    }

    public function up(Schema $schema): void
    {
        // no-op
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
