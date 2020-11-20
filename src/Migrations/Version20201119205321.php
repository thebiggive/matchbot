<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update 3 Mousetrap Theatre missed donations to Paid status and mark for re-pushing to Salesforce.
 */
final class Version20201119205321 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Update 3 Mousetrap Theatre missed donations to Paid status and mark for re-pushing to Salesforce.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $updateSql = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donationStatus = 'Paid'
WHERE salesforceId IN ('a066900001kFNkrAAG', 'a066900001kFNuSAAW', 'a066900001kFOgNAAW')
LIMIT 3
EOT;
        $this->addSql($updateSql);
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        // No safe un-fix -> no-op.
    }
}
