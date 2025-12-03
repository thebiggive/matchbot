<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251203162338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete donor multiple donor accounts';
    }

    public function up(Schema $schema): void
    {
        // uuids in order are for the accounts mentioned on Jira tickets BG2-3039 (one account) and BG2-3041
        // (four accounts)

        $this->addSql(<<<SQL
            DELETE FROM DonorAccount
            WHERE DonorAccount.uuid IN (
                '1ed7343f-948b-685a-b46c-5186792a1146',
                '1f0ced94-1a86-608e-a371-5bace4e4e829',
                '1f0cebca-913d-6a36-afe7-953cf4bccf3e',
                '1f0cde78-2950-6e78-9a4f-2fd251820bfe',
                '1f0cf949-81e4-6a3a-b916-63bb94a04df2'                               
                )
            LIMIT 5
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
