<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-251 – mark donations for re-claiming where charity provided incorrect ref and HMRC.
 */
final class Version20220623122551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark some donations for re-claiming for ticket MAT-251 (as two charities had wrong HMRC references).';
    }

    public function up(Schema $schema): void
    {
        // MAT-251. 76 donations to the affected charity not fully processed, hence LIMIT 76 added.
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
            LIMIT 76
            EOT,
            [
                'correlationIdsToResubmit' => [
                    // First 5 for 31 donations to Global Ecovillage Network (charity id 1718)
                    'F18E8038C1694F27B58208A22F63C9FD',
                    'C0FCB56D3352438DB49A8E1563E62E0F',
                    '416F0EBB7581473F86B7513A1FED2CEC',
                    'ABA1A41CDC6A400A87E0144577D0845F',
                    'C11284ACA90940E5A24C46D458AF6FC1',
                    // Second 5 for 45 donations to Cambridge Rape Crisis Centre (charity id 1656)
                    '770F55B41EB34BB1AEDF43EAE971420F',
                    '1A35A214B81A41BBA6DAF78164B73FF8',
                    '2011B9F341D54A4DB3A281CDD0B43465',
                    '287E66C1CE4246C1AACC0144CA2212AC',
                    'C02E6128327648649D56C56ECC05B245',
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
