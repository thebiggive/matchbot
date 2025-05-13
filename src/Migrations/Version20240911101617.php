<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20240911101617 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Pause collections from existing test regular giving mandates in staging env.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'staging') {
            return;
        }

        // Attempting to collect donations for any of these mandates triggers an error, as a mandate is expected
        // to have at least one donation taken on-session at creation time, and these existing ones do not.
        $this->addSql(<<<SQL
            UPDATE RegularGivingMandate SET RegularGivingMandate.donationsCreatedUpTo = '3000-01-01' LIMIT 9;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE RegularGivingMandate SET RegularGivingMandate.donationsCreatedUpTo = null LIMIT 9;
        SQL
        );
    }
}
