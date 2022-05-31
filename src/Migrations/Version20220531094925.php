<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-246 – un-mark donations for future claiming where charity has not provided required info.
 * MAT-250 – mark donations for re-claiming where charity provided incorrect ref and HMRC have no
 *           record of the previous submission to 'patch'.
 */
final class Version20220531094925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark some donations as never claimable and some for re-claiming';
    }

    public function up(Schema $schema): void
    {
        // MAT-246.
        $this->addSql(<<<EOT
            UPDATE Donation SET tbgShouldProcessGiftAid = 0
            WHERE campaign_id = :campaignId AND
                  giftAid = 1 AND
                  tbgShouldProcessGiftAid = 1
            LIMIT 76
EOT,
            [
                'campaignId' => 3016, // The one campaign for internal Charity ID 1632.
            ],
        );

        // MAT-250. 25 donations to the affected charity not fully processed.
        $this->addSql(<<<EOT
UPDATE Donation
SET
    tbgGiftAidRequestQueuedAt = NULL,
    tbgGiftAidRequestFailedAt = NULL,
    tbgGiftAidRequestConfirmedCompleteAt = NULL,
    tbgGiftAidRequestCorrelationId = NULL,
    tbgGiftAidResponseDetail = NULL
WHERE
    tbgGiftAidRequestQueuedAt IS NOT NULL AND
    tbgGiftAidRequestCorrelationId IN (:correlationIdsToResubmit)
LIMIT 25
EOT,
            [
                'correlationIdsToResubmit' => [
                    '271C66984399459FB28366A527897AB6',
                    '3AE1F6653D9F43C795E570DDA5C5E83C',
                    '8E237A0CB284419C98F1EC0DFB40B38B',
                ],
            ],
            // https://stackoverflow.com/a/36710894/2803757
            [
                'correlationIdsToResubmit' => Connection::PARAM_STR_ARRAY,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
