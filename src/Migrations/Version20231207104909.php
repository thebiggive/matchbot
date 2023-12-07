<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231207104909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove gift aid from one donation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = '324ec5b4-8f53-45fd-b90a-b25f97e55219' 
            AND transactionId = 'tr_3OJvXSKkGuKkxwBN0NVpIJSU' -- https://dashboard.stripe.com/connect/transfers/tr_3OJvXSKkGuKkxwBN0NVpIJSU
            LIMIT 1
            EOT
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back!");
    }
}
