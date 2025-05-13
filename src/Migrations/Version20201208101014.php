<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-147 - name data edge case fixes.
 */
final class Version20201208101014 extends AbstractMigration
{
    #[\Override]
    public function getDescription() : string
    {
        return 'Fix long name and stuck update Production data edge cases';
    }

    #[\Override]
    public function up(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $updateLongNameDonation = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donorFirstName = 'Joshua'
WHERE uuid = '98712b7b-774f-4d15-890f-34026a17ad86' AND salesforcePushStatus = 'updating'
LIMIT 1
EOT;
        $this->addSql($updateLongNameDonation);

        $updateMysteryStuckDonation = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update'
WHERE uuid = '9ae7719f-e47a-433f-b39f-b159bac52f79' AND salesforcePushStatus = 'updating'
LIMIT 1
EOT;
        $this->addSql($updateMysteryStuckDonation);
    }

    #[\Override]
    public function down(Schema $schema) : void
    {
        $this->abortIf(! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        // No safe un-fix -> no-op.
    }
}
