<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Assertion;

/**
 * Remove Gift Aid from 4 donations
 */
final class Version20250115173419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Gift Aid from 4 donations';
    }

    public function up(Schema $schema): void
    {
        /**
         * @var array{uuid: string, transactionId: string}[]
         */
        $idPairs = [
            ['uuid' => '8589927d-b240-4270-b40f-d7ba8cf57110', 'transactionId' => 'pi_3QRuzgKkGuKkxwBN1ZhfdDCc'],
            ['uuid' => '181991a7-4e75-4d43-996b-943f72793c3b', 'transactionId' => 'pi_3QRvSrKkGuKkxwBN1A9XRWkO'],
            ['uuid' => 'cd690ac2-1400-4428-becc-f8973d8492c4', 'transactionId' => 'pi_3QSdtwKkGuKkxwBN1ceHaFKd'],
            ['uuid' => '7feaa65c-3afb-40ec-874a-ccfb77d2c1d4', 'transactionId' => 'pi_3QRyKqKkGuKkxwBN0vvIXTjL'],
        ];
        Assertion::count($idPairs, 4);

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
