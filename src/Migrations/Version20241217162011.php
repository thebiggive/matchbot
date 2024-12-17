<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Assertion;

/**
 * MAT-398 – remove Gift Aid from 2 donations
 *
 * @see Version20241206101546 which Gift Aid patch approach is copied from.
 */
final class Version20241217162011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Gift Aid from 2 donations';
    }

    public function up(Schema $schema): void
    {
        /**
         * @var array{uuid: string, transactionId: string}[]
         */
        $idPairs = [
            ['uuid' => '398c87ed-0025-4339-8f02-b222f13637c4', 'transactionId' => 'pi_3QT7B3KkGuKkxwBN1OYeHmPS'],
            ['uuid' => '1d4f2883-4002-4fe9-83a8-e10ddc2312e4', 'transactionId' => 'pi_3QSfNSKkGuKkxwBN0pfDmCia'],
        ];
        Assertion::count($idPairs, 2);

        foreach ($idPairs as $idPair) {
            $this->addSql(<<<EOT
                UPDATE Donation
                SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
                WHERE uuid = '{$idPair['uuid']}' AND transactionId = '{$idPair['transactionId']}'
                LIMIT 1
            EOT
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
