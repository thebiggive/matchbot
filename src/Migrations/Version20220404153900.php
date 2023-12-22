<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-19 – update 4 April morning donations' Gift Aid claim data.
 */
final class Version20220404153900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Update 4 April 2022 morning donations' Gift Aid claim data";
    }

    public function up(Schema $schema): void
    {
        $donationIdsClaimed = [
            '19b8da6a-0f32-4b00-8212-984ec54d7660',
            'e47d0cd9-f8e0-4218-bb35-73b5a57a1ea2',
            '04361c8c-7b35-4513-8195-673e20efae0f',
            '6eee4df8-912a-4d08-b872-95a63b853b20',
            '4e7499bb-7c4f-483a-8851-e91bc5205d59',
            '3d5748a7-db71-409a-82a5-8ad0e7cabff0',
            'c50c3af8-7f4c-4f52-b56f-775e7d2d2bb8',
            'a7eb0623-1ba3-46b2-b9ee-734af9fa8cd7',
            '60344283-1ebd-41d6-bdff-35a411e67e8c',
            '6091abe0-d06e-4ccf-9a8e-a3b553f7557b',
        ];

        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestConfirmedCompleteAt = NOW(),
    tbgGiftAidRequestCorrelationId = :correlationId,
    tbgGiftAidResponseDetail = :responseDetail
WHERE
    tbgGiftAidRequestQueuedAt IS NOT NULL AND
    tbgGiftAidRequestConfirmedCompleteAt IS NULL AND
    tbgGiftAidRequestCorrelationId IS NULL AND
    uuid IN (:completeUuids)
LIMIT 10
EOT,
            [
                'correlationId' => '6DB2614C26324981B0CB34C19FE6FF00',
                'responseDetail' => 'Thank you for your submission',
                'completeUuids' => $donationIdsClaimed,
            ],
            // https://stackoverflow.com/a/36710894/2803757
            [
                'correlationId' => \PDO::PARAM_STR,
                'responseDetail' => \PDO::PARAM_STR,
                'completeUuids' => ArrayParameterType::STRING,
            ]
        );

        $this->addSQL(<<<EOT
UPDATE Donation
SET tbgGiftAidRequestQueuedAt = NULL
WHERE
    tbgGiftAidRequestQueuedAt IS NOT NULL AND
    tbgGiftAidRequestConfirmedCompleteAt IS NULL AND
    tbgGiftAidRequestCorrelationId IS NULL AND
    uuid NOT IN (:completeUuids)
LIMIT 10
EOT,
            [
                'completeUuids' => $donationIdsClaimed,
            ],
            // https://stackoverflow.com/a/36710894/2803757
            [
                'completeUuids' => ArrayParameterType::STRING,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // No un-fix.
    }
}
