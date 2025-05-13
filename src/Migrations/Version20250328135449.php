<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mark Donation.tbgGiftAidRequestQueuedAt as datetime_immutable
 */
final class Version20250328135449 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Mark Donation.tbgGiftAidRequestQueuedAt as datetime_immutable';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE tbgGiftAidRequestQueuedAt tbgGiftAidRequestQueuedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE tbgGiftAidRequestQueuedAt tbgGiftAidRequestQueuedAt DATETIME DEFAULT NULL');
    }
}
