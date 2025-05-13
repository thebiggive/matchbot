<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Some donations today couldn't be created as normal, marking them 'not-sent' so we stop attempting to send to SF.
 * @see Version20201114144300
 */
final class Version20241118151933 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Stop attempting to send today\'s un-sendable donations to SF';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Donation set salesforcePushStatus = 'not-sent'
            WHERE transactionId is null
            AND salesforcePushStatus = 'pending-create'
            AND campaign_id = 7282
            AND createdAt < '2024-11-18 14:00:00'
            LIMIT 7;
SQL
);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("No un-patch");
    }
}
