<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-19 – update 4 April afternoon donations' Gift Aid claim data.
 */
final class Version20220404165640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Update 4 April 2022 afternoon donations' Gift Aid claim data";
    }

    public function up(Schema $schema): void
    {
        $correlationId = '8BF8ADF17D304ED7B5032A8692D3B6EC';
        $responseDetail = 'Thank you for your submission';
        $donationIdsClaimed = [
            'e7683fa7-8023-4bd8-8698-c9e5c44a926c',
            '494581a0-b1c5-490f-baa7-18520e45175b',
            'c5905292-5d1b-4133-9900-71021acc8db8',
            '9de39012-8a3d-4707-8825-54916dbc896d',
            '69137ea5-a6fb-420e-98ce-e86de62c6e83',
            '087d76f0-f648-4404-bc70-33c15aa0d258',
            '256e2968-f842-4c11-9d8f-a838a86d3928',
            'c5975a71-5cb0-438e-a23e-99d221461ea4',
            'ce7d2156-8bb9-4b58-bf9b-e1cac96c2402',
            '55691f35-a3db-411c-9b79-02c6666debb6',
        ];

        $donationIdsQueuedAndNotClaimed = [
            'e2f3411a-7702-4163-8437-d7394ad2aa17',
            '84d5072f-f188-45df-af1a-ec6d42ff9057',
            '631c3152-d275-40cc-916e-ddc92d2ed99c',
            'e2e86bad-c4bd-416a-ad10-856f7d87cc1c',
            '9b931901-a212-42d8-b4e9-2f590c8881dd',
            '474962fb-72ef-4fba-be9d-d878d83a7c79',
            'e73a00e7-bacc-44bb-bc98-4192683dcb99',
            '35a4bb94-2596-4082-b6d3-cc4cd7698cfc',
            '958797b9-4558-4f1e-a7a1-bf9abc5bf158',
            '71f8eaea-c6d1-49a0-b9ae-b95914f3cee1',
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
                'correlationId' => $correlationId,
                'responseDetail' => $responseDetail,
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
    uuid IN (:notCompleteUuids)
LIMIT 10
EOT,
            [
                'notCompleteUuids' => $donationIdsQueuedAndNotClaimed,
            ],
            // https://stackoverflow.com/a/36710894/2803757
            [
                'notCompleteUuids' => ArrayParameterType::STRING,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // No un-fix.
    }
}
