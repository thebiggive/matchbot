<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration. For some reason Doctrine doesn't seem to want to auto generate, despite having annotated
 * the DonorAccount class with Index and UniqueConstraint - and it generates a migration to reverse this when I run
 * vendor/bin/doctrine-migrations diff. For now we'll just have to delete that auto generated migration.
 */
final class Version20231018104935 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add unique index ON DonorAccount(stripeCustomerId) - for faster lookups + prevent duplicate entries';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // we have a duplicate already in staging, but we don't care much about any of the data in this table yet.
        $this->addSql('DELETE FROM DonorAccount WHERE ID > 0');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STRIPE_ID ON DonorAccount(stripeCustomerId) ');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_STRIPE_ID ON DonorAccount');

    }
}
