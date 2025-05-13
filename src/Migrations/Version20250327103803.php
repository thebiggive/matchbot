<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2854 – Remove Gift Aid from one donation
 */
final class Version20250327103803 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove Gift Aid from one donation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        /**
         * @var array{uuid: string, transactionId: string}
         */
        $idPair = [
            'uuid' => '01f1edad-1f3b-4441-a21c-6c031eda9ee8',
            'transactionId' => 'pi_3R5k5KKkGuKkxwBN0SIhyZre',
        ];

        $this->addSql(<<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0, `giftAidRemovedAt` = now()
            WHERE uuid = '{$idPair['uuid']}' AND transactionId = '{$idPair['transactionId']}'
            LIMIT 1
        EOT);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
