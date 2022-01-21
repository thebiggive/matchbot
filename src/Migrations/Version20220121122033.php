<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-227 - un-mark donations sent for Gift Aid processing so latest code can be
 * tested on Staging.
 */
final class Version20220121122033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un-mark donations sent for Gift Aid processing so latest code can be tested on Staging';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->skipIf(
            getenv('APP_ENV') !== 'staging',
            'Gift Aid testing data patch migration is for Staging only',
        );

        $this->addSql('UPDATE Donation SET tbgGiftAidRequestQueuedAt = NULL WHERE tbgGiftAidRequestQueuedAt IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
