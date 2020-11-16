<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Set all donations which can't be pushed to Salesforce but have statuses suggesting otherwise to 'not-sent'.
 * This is a deliberate duplicate of the previous update to catch donations that came in during deploys.
 */
final class Version20201114144300 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Fix Salesforce push status on existing donations, again';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $updateSql = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'not-sent'
WHERE salesforcePushStatus = 'pending-update' AND (donorFirstName IS NULL OR donorLastName IS NULL)
EOT;
        $this->addSql($updateSql);
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        // No safe un-fix -> no-op.
    }
}
