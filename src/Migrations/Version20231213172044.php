<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231213172044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = 'cba210e5-8d73-41f5-950a-d62a950e7ad9'
            AND transactionId = 'pi_3OIX3tKkGuKkxwBN0wloV1kX'
            LIMIT 1
            EOT
        );

    }

    public function down(Schema $schema): void {}
}
