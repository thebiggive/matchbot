<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove Gift Aid from 1 donation
 */
final class Version20241220145106 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove Gift Aid from 1 donation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        /**
         * @var array{uuid: string, transactionId: string}
         */
        $idPair = [
            'uuid' => '1cdd7b8c-6390-4979-b22f-44110bfd165d',
            'transactionId' => 'pi_3QSfprKkGuKkxwBN1HNyI4yd',
        ];

        $this->addSql(<<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = '{$idPair['uuid']}' AND transactionId = '{$idPair['transactionId']}'
            LIMIT 1
        EOT);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
