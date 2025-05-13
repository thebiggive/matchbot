<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-231 – one-[more]-time Gift Aid processed flag clear to deal with bug released earlier today.
 */
final class Version20220201171800 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Un-mark donations sent for Gift Aid processing prematurely';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('UPDATE Donation SET tbgGiftAidRequestQueuedAt = NULL WHERE tbgGiftAidRequestQueuedAt IS NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
