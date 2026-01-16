<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260116170743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update donation to correct tip amount';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Donation SET tipAmount = 0, tipRefundAmount = 100, salesforcePushStatus = 'PENDING'
            WHERE Donation.uuid = "0c61bb48-666a-4d52-b145-0c93cbec4d71"
            LIMIT 1
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \RuntimeException('Cannot rollback donation tip amount update');
    }
}
