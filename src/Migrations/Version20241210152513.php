<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241210152513 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove gift aid added to donation by mistake';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<SQl
                UPDATE Donation 
                SET giftAid = 0, salesforcePushStatus = 'pending-update' 
                WHERE uuid = 'b69c0dfa-d252-4285-9f5e-045fae2157ad' and salesforceId = 'a06WS0000071v0DYAQ'
                LIMIT 1
            SQl
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no un-patch
    }
}
