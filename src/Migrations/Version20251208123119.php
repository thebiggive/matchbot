<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-469 - Amend email address on donation from CC25
 */
final class Version20251208123119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // MySQL doesn't allow selecting from the same table being updated.
        // Fetch the source value first, then use it as a bound parameter in the UPDATE.
        $sourceUuid = '3d57e857-4830-4088-9edd-18b32b4dccc1';
        $targetUuid = 'b59e65f7-3bae-47ce-94b2-5d0811b06917';

        /** @var string|false $email */
        $email = $this->connection->fetchOne(
            'SELECT donorEmailAddress FROM Donation WHERE uuid = ? LIMIT 1',
            [$sourceUuid],
        );

        if ($email === false) {
            // Nothing to update if the source row doesn't exist or has no email.
            return;
        }

        $this->addSql(
            'UPDATE Donation SET donorEmailAddress = ? WHERE uuid = ? LIMIT 1',
            [$email, $targetUuid],
        );
    }

    public function down(Schema $schema): void
    {
        // no un-patch
    }
}
