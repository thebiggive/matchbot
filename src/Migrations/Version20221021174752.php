<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update missed donation to Paid status and mark for re-pushing to Salesforce.
 */
final class Version20221021174752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update missed donation to Paid status and mark for re-pushing to Salesforce';
    }

    public function up(Schema $schema): void
    {
        $updateSql = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donationStatus = 'Paid'
WHERE salesforceId = :sfId
LIMIT 1
EOT;
        $this->addSql($updateSql, [
            'sfId' => 'a066900001u4YTvAAM',
        ]);
    }

    public function down(Schema $schema): void
    {
        // No un-fix.
    }
}
