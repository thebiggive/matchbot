<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add field for Stripe Connected Account ID
 */
final class Version20200720102604 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add field for Stripe Connected Account ID';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Charity ADD stripeAccountId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4CC08E8293A8A818 ON Charity (stripeAccountId)');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX UNIQ_4CC08E8293A8A818 ON Charity');
        $this->addSql('ALTER TABLE Charity DROP stripeAccountId');
    }
}
