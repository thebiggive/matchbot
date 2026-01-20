<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260120122602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adjust salesforce push status on donation with refunded tip';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE Donation SET salesforcePushStatus = \'pending-update\' WHERE uuid = \'0c61bb48-666a-4d52-b145-0c93cbec4d71\' LIMIT 1');
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('Cannot rollback this migration');
    }
}
