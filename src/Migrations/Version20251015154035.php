<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2993 Correct a Gift Aid opt-in flag on one donation
 */
final class Version20251015154035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct a Gift Aid opt-in flag on one donation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation
            SET giftAid = 1, tipGiftAid = 1, salesforcePushStatus = 'pending-update'
            WHERE transactionId = 'pi_3SISIYKkGuKkxwBN13hnKZxE' AND uuid = 'aae7207a-44ee-4855-8d5a-fe9f67c40e78'
            LIMIT 1
        EOT);
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
