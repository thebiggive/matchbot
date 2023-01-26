<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-290 – Patch paid out donation statuses.
 */
final class Version20230124121155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Patch paid out donation statuses';
    }

    public function up(Schema $schema): void
    {
        $updateStatusSql = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donationStatus = 'Paid'
WHERE
    donationStatus = 'Collected' AND
    createdAt BETWEEN :firstDate AND :lastDate AND
    campaign_id NOT IN (:campaignIdsToNotPatch)
EOT;
        $this->addSql(
            $updateStatusSql,
            [
                'campaignIdsToNotPatch' => [
                    3493,
                    3728,
                    3809,
                    3922,
                    4150,
                    4431,
                ],
                'firstDate' => '2022-11-16 00:00:00', // UTC === local UK in this case.
                'lastDate' => '2023-01-09 23:59:59',
            ],
            [
                'campaignIdsToNotPatch' => Connection::PARAM_INT_ARRAY,
                'firstDate' => ParameterType::STRING,
                'lastDate' => ParameterType::STRING,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
