<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-285 – Patch some CC22 edge cases to have new `collectedAt` field set.
 */
final class Version20230117173337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Patch 3x donation values';
    }

    public function up(Schema $schema): void
    {
        $updateCollectedAtSql = <<<EOT
UPDATE DonationXXXXXXXXXx
SET salesforcePushStatus = 'pending-update', donationStatus = 'Paid', collectedAt = createdAt
WHERE salesforceId IN (:sfIds)
LIMIT 3
EOT;
        $this->addSql(
            $updateCollectedAtSql,
            [
                'sfIds' => [
                    'a066900001u4sBVAAY', // See Version20221101143104
                    'a066900001u4sD7AAI', // See Version20221101143104
                    'a066900001vVp3kAAC', // See Version20221203151735
                ],
            ],
            [
                'sfIds' => Connection::PARAM_STR_ARRAY,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
