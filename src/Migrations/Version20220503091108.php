<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-245 – patch one GMF donation where Gift Aid was claimed in error.
 */
final class Version20220503091108 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Patch one GMF donation where Gift Aid was claimed in error';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation SET giftAid = 0, tipGiftAid = 0, salesforcePushStatus = 'pending-update'
            WHERE uuid = :uuid AND salesforceId = :salesforceId AND giftAid = 1
            LIMIT 1
EOT,
            [
                'uuid' => 'b8fed725-70b2-41f7-aa65-803bbff89632',
                'salesforceId' => 'a066900001uHRJzAAO',
            ],
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
