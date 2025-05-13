<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-260 – Update 2 more missed donations to Paid status and mark for
 * re-pushing to Salesforce.
 */
final class Version20221101143104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update missed donations to Paid status and mark for re-pushing to Salesforce';
    }

    public function up(Schema $schema): void
    {
        $updateSql = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donationStatus = 'Paid'
WHERE salesforceId IN (:sfIds)
LIMIT 2
EOT;
        $this->addSql(
            $updateSql,
            [
                'sfIds' => [
                    'a066900001u4sBVAAY',
                    'a066900001u4sD7AAI',
                ],
            ],
            [
                'sfIds' => ArrayParameterType::STRING,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No un-fix.
    }
}
