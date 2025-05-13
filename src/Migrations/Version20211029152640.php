<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add new Donation fields for in-house Gift Aid processing. Add and populated a `collectedAt` datetime.
 */
final class Version20211029152640 extends AbstractMigration
{
    #[\Override]
    public function getDescription() : string
    {
        return 'Add new Donation fields for in-house Gift Aid processing; add and populated a `collectedAt` datetime';
    }

    #[\Override]
    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation ADD collectedAt DATETIME NOT NULL, ADD tbgShouldProcessGiftAid TINYINT(1) DEFAULT NULL, ADD tbgGiftAidRequestQueuedAt TINYINT(1) DEFAULT NULL, ADD tbgGiftAidRequestFailedAt TINYINT(1) DEFAULT NULL');

        // Set new collectedAt field to the best guess we have at roughly when the donation was completed by
        // the donor – this will be up to a few minutes before the real time for pre-existing donations.
        $this->addSql("UPDATE Donation SET collectedAt = createdAt WHERE collectedAt IS NULL AND (donationStatus IN ('Collected', 'Paid'))");
    }

    #[\Override]
    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation DROP collectedAt, DROP tbgShouldProcessGiftAid, DROP tbgGiftAidRequestQueuedAt, DROP tbgGiftAidRequestFailedAt');
    }
}
