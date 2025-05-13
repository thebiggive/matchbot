<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231214170316 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove gift aid for donation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE salesforceId = 'a0669000020JdoyAAC'
            LIMIT 1
            EOT
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
