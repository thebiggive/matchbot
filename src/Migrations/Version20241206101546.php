<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Assertion;

/**
 * MAT-394 – correct Gift Aid claimed in error.
 */
final class Version20241206101546 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct Gift Aid claimed in error';
    }

    public function up(Schema $schema): void
    {
        /**
         * @var array{uuid: string, transactionId: string}[]
         */
        $idPairs = [
            ['uuid' => '549b259f-02d6-4b68-939d-c297d1b88076', 'transactionId' => 'pi_3QSQlcKkGuKkxwBN1iTNDzHq'],
            ['uuid' => '68749582-0793-47d5-9930-e40fddd4d42a', 'transactionId' => 'pi_3QSN9iKkGuKkxwBN1rV6OWqD'],
            ['uuid' => '09680f0a-042f-426a-a396-9dfe8e0796f1', 'transactionId' => 'pi_3QU4GAKkGuKkxwBN1WmNurM1'],
            ['uuid' => '3c22adf8-129b-4e71-85a1-77d648b96dc5', 'transactionId' => 'pi_3QSMkOKkGuKkxwBN0YiPubBG'],
            ['uuid' => '31ef0f2a-c2da-4d4e-9b7a-07faa8c48c14', 'transactionId' => 'pi_3QSMo4KkGuKkxwBN0Wndxcjc'],
            ['uuid' => '5a7bf322-17d0-462f-9dbc-4c2db1a6a1d8', 'transactionId' => 'pi_3QTq9YKkGuKkxwBN1wjOfgzJ'],
            ['uuid' => 'd33a3b39-b914-41b3-9cfa-b0073a29b392', 'transactionId' => 'pi_3QU9r7KkGuKkxwBN0RPJjyf5'],
            ['uuid' => '8d5aa18c-c055-4d49-ab96-1db28a4ae89a', 'transactionId' => 'pi_3QTjMGKkGuKkxwBN0yUymRO7'],
        ];
        Assertion::count($idPairs, 8);

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
