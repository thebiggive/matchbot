<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-6 - fix types for Gift Aid queued at + failed at fields.
 */
final class Version20220107173738 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Fix types for Gift Aid queued at + failed at fields';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE tbgGiftAidRequestQueuedAt tbgGiftAidRequestQueuedAt DATETIME DEFAULT NULL, CHANGE tbgGiftAidRequestFailedAt tbgGiftAidRequestFailedAt DATETIME DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE tbgGiftAidRequestQueuedAt tbgGiftAidRequestQueuedAt TINYINT(1) DEFAULT NULL, CHANGE tbgGiftAidRequestFailedAt tbgGiftAidRequestFailedAt TINYINT(1) DEFAULT NULL');
    }
}
