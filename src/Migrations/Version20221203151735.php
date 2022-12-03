<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-260 – fix one donation whose status was reverted in a donor PUT.
 *
 * @see Version20221101143104
 */
final class Version20221203151735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update a missed donation to Paid status and mark for re-pushing to Salesforce';
    }

    public function up(Schema $schema): void
    {
        $updateSql = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donationStatus = 'Collected'
WHERE salesforceId IN (:sfIds)
LIMIT 1
EOT;

        // Sticking with str array to minimise differences from `Version20221101143104`.
        $this->addSql(
            $updateSql,
            [
                'sfIds' => [
                    'a066900001vVp3kAAC',
                ],
            ],
            [
                'sfIds' => Connection::PARAM_STR_ARRAY,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No un-fix.
    }
}
